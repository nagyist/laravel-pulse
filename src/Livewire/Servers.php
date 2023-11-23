<?php

namespace Laravel\Pulse\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Pulse\Queries\Servers as ServersQuery;
use Livewire\Attributes\Lazy;

#[Lazy]
class Servers extends Card
{
    use Concerns\HasPeriod, Concerns\RemembersQueries;

    /**
     * Render the component.
     */
    public function render(ServersQuery $query): Renderable
    {
        $now = new CarbonImmutable();

        $servers = DB::table('pulse_values')
            ->where('key', 'like', 'system:%')
            ->get()
            ->mapWithKeys(function ($system) use ($now) {
                $values = json_decode($system->value, flags: JSON_THROW_ON_ERROR);

                return [
                    Str::after($system->key, 'system:') => (object) [
                        'name' => $values->name,
                        'cpu_current' => $values->cpu,
                        'memory_current' => $values->memory_used,
                        'memory_total' => $values->memory_total,
                        'storage' => $values->storage,
                        'updated_at' => $updatedAt = CarbonImmutable::createFromTimestamp($values->timestamp),
                        'recently_reported' => $updatedAt->isAfter($now->subSeconds(30)),
                    ],
                ];
            });

        $maxDataPoints = 60;
        $periodInSeconds = (int) $this->periodAsInterval()->totalSeconds;
        $secondsPerPeriod = ($periodInSeconds / $maxDataPoints);
        $currentBucket = (int) floor($now->timestamp / $secondsPerPeriod) * $secondsPerPeriod;
        $earliestBucketWithinPeriod = $currentBucket - (($maxDataPoints - 1) * $secondsPerPeriod);

        $latestAggregatedBucket = DB::table('pulse_aggregates')
            ->where('period', $periodInSeconds)
            ->whereIn('type', ['cpu', 'memory'])
            ->latest('bucket')
            ->value('bucket') ?? 0;

        $nextBucketToAggregate = $latestAggregatedBucket + $secondsPerPeriod;

        if ($nextBucketToAggregate < $currentBucket) {
            DB::table('pulse_aggregates')->insertUsing(
                ['bucket', 'period', 'type', 'key', 'value'],
                DB::table('pulse_entries')
                    ->selectRaw('FLOOR(`timestamp` / ?) * ? AS `bucket`', [$secondsPerPeriod, $secondsPerPeriod])
                    ->selectRaw("{$periodInSeconds} AS `period`")
                    ->addSelect('type', 'key')
                    ->selectRaw('ROUND(AVG(`value`)) AS `value`')
                    ->whereIn('type', ['cpu', 'memory'])
                    ->where('timestamp', '>=', max($earliestBucketWithinPeriod, $nextBucketToAggregate))
                    ->where('timestamp', '<', $currentBucket)
                    ->groupBy('type', 'key', 'bucket')
            );
        }

        $padding = collect()
            ->range(0, 59)
            ->mapWithKeys(fn ($i) => [
                Carbon::createFromTimestamp($earliestBucketWithinPeriod + ($i * $secondsPerPeriod))->toDateTimeString() => null,
            ]);

        DB::table('pulse_aggregates')
            ->select('bucket', 'type', 'key', 'value')
            ->where('period', $periodInSeconds)
            ->whereIn('type', ['cpu', 'memory'])
            ->where('bucket', '>=', $earliestBucketWithinPeriod)
            ->orderBy('bucket')
            ->unionAll(
                DB::table('pulse_entries')
                    ->selectRaw("{$currentBucket} AS `bucket`")
                    ->addSelect('type', 'key')
                    ->selectRaw('ROUND(AVG(`value`)) AS `value`')
                    ->whereIn('type', ['cpu', 'memory'])
                    ->where('timestamp', '>=', $currentBucket)
                    ->groupBy('type', 'key')
            )
            ->get()
            ->groupBy('key')
            ->map(fn ($readings) => $readings
                ->groupBy('type')
                ->map(fn ($readings) => $padding->merge(
                    $readings->mapWithKeys(fn ($row) => [
                        Carbon::createFromTimestamp($row->bucket)->toDateTimeString() => (int) $row->value,
                    ])
                ))
            )
            ->each(function ($readings, $server) use (&$servers) {
                $servers[$server]->cpu = $readings['cpu'] ?? collect([]);
                $servers[$server]->memory = $readings['memory'] ?? collect([]);
            });

        // [$servers, $time, $runAt] = $this->remember($query);

        if (request()->hasHeader('X-Livewire')) {
            $this->dispatch('servers-chart-update', servers: $servers);
        }

        $time = 0;
        $runAt = 0;

        return View::make('pulse::livewire.servers', [
            'servers' => $servers,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * Render the placeholder.
     */
    public function placeholder(): Renderable
    {
        return View::make('pulse::components.servers-placeholder', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
        ]);
    }
}
