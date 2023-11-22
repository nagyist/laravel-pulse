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
        // Backfill missing aggregate buckets for the selected period.
        DB::listen(dump(...));

        $now = new CarbonImmutable();

        $systems = DB::table('pulse_values')
            ->where('key', 'like', 'system:%')
            ->get()
            ->mapWithKeys(fn ($system) => [
                Str::after($system->key, 'system:') => json_decode($system->value),
            ]);

        $interval = $this->periodAsInterval();

        $period = $interval->totalSeconds;

        $maxDataPoints = 60;
        $secondsPerPeriod = ($interval->totalSeconds / $maxDataPoints);
        $currentBucket = (int) floor($now->timestamp / $secondsPerPeriod) * $secondsPerPeriod;
        $firstBucket = $currentBucket - (($maxDataPoints - 1) * $secondsPerPeriod);

        $latestAggregatedBucket = DB::table('pulse_aggregates')
            ->where('period', $period)
            ->whereIn('type', ['cpu', 'memory'])
            ->latest('bucket')
            ->value('bucket') ?? 0;

        if ($latestAggregatedBucket + $secondsPerPeriod < $currentBucket) {
            DB::table('pulse_aggregates')->insertUsing(
                ['bucket', 'period', 'type', 'key', 'value'],
                DB::table('pulse_entries')
                    ->selectRaw('FLOOR(`timestamp` / ?) * ? AS `bucket`', [$secondsPerPeriod, $secondsPerPeriod])
                    ->selectRaw("$period AS `period`")
                    ->addSelect('type')
                    ->addSelect('key')
                    ->selectRaw('ROUND(AVG(`value`)) AS `value`')
                    ->whereIn('type', ['cpu', 'memory'])
                    ->where('timestamp', '>=', max($firstBucket, $latestAggregatedBucket + $secondsPerPeriod))
                    ->where('timestamp', '<', $currentBucket)
                    ->groupBy('key', 'bucket', 'type')
            );
        }

        $padding = collect()
            ->range(0, 59)
            ->mapWithKeys(fn ($i) => [Carbon::createFromTimestamp($firstBucket + $i * $secondsPerPeriod)->toDateTimeString() => null]);

        DB::table('pulse_aggregates')
            ->select([
                'bucket',
                'type',
                'key',
                'value',
            ])
            ->whereIn('type', ['cpu', 'memory'])
            ->where('period', $period)
            ->where('bucket', '>=', $firstBucket)
            ->orderBy('bucket')
            ->unionAll(
                DB::table('pulse_entries')
                    ->selectRaw("$currentBucket AS `bucket`")
                    ->addSelect('type')
                    ->addSelect('key')
                    ->selectRaw('ROUND(AVG(`value`)) AS `value`')
                    ->whereIn('type', ['cpu', 'memory'])
                    ->where('timestamp', '>=', $currentBucket)
                    ->groupBy('key', 'type')
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
            ->each(function ($readings, $server) use (&$systems) {
                $systems[$server]->cpu = $readings['cpu']?->toArray() ?? [];
                $systems[$server]->memory = $readings['memory']?->toArray() ?? [];
            });

        dump($systems->toArray());

        return View::make('pulse::livewire.servers', [
            'servers' => collect([]),
            'time' => 0,
            'runAt' => 0,
        ]);

        // Retrieve aggregated buckets

        [$servers, $time, $runAt] = $this->remember($query);

        dd($servers['pulse-demo']->readings);

        if (request()->hasHeader('X-Livewire')) {
            $this->dispatch('servers-chart-update', servers: $servers);
        }

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
        return View::make('pulse::components.servers-placeholder', ['cols' => $this->cols, 'rows' => $this->rows, 'class' => $this->class]);
    }
}
