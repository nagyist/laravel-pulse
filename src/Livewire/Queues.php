<?php

namespace Laravel\Pulse\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Pulse\Queries\Queues as QueuesQuery;
use Laravel\Pulse\Recorders\Jobs;
use Livewire\Attributes\Lazy;

#[Lazy]
class Queues extends Card
{
    use Concerns\HasPeriod, Concerns\RemembersQueries;

    /**
     * Render the component.
     */
    public function render(QueuesQuery $query): Renderable
    {
        // DB::listen(dump(...));
        $now = new CarbonImmutable();

        $interval = $this->periodAsInterval();

        $period = $interval->totalSeconds;

        $maxDataPoints = 60;
        $secondsPerPeriod = ($interval->totalSeconds / $maxDataPoints);
        $currentBucket = (int) floor($now->timestamp / $secondsPerPeriod) * $secondsPerPeriod;
        $firstBucket = $currentBucket - (($maxDataPoints - 1) * $secondsPerPeriod);

        $latestAggregatedBucket = DB::table('pulse_aggregates')
            ->where('period', $period)
            ->whereIn('type', ['queued', 'processing', 'processed', 'released', 'failed'])
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
                    ->selectRaw('COUNT(*) AS `value`')
                    ->whereIn('type', ['queued', 'processing', 'processed', 'released', 'failed'])
                    ->where('timestamp', '>=', max($firstBucket, $latestAggregatedBucket + $secondsPerPeriod))
                    ->where('timestamp', '<', $currentBucket)
                    ->groupBy('key', 'bucket', 'type')
            );
        }

        $padding = collect()
            ->range(0, 59)
            ->mapWithKeys(fn ($i) => [Carbon::createFromTimestamp($firstBucket + $i * $secondsPerPeriod)->toDateTimeString() => null]);

        $queues = DB::table('pulse_aggregates')
            ->select([
                'bucket',
                'type',
                'key',
                'value',
            ])
            ->whereIn('type', ['queued', 'processing', 'processed', 'released', 'failed'])
            ->where('period', $period)
            ->where('bucket', '>=', $firstBucket)
            ->orderBy('bucket')
            ->unionAll(
                DB::table('pulse_entries')
                    ->selectRaw("$currentBucket AS `bucket`")
                    ->addSelect('type')
                    ->addSelect('key')
                    ->selectRaw('COUNT(*) AS `value`')
                    ->whereIn('type', ['queued', 'processing', 'processed', 'released', 'failed'])
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
            );

        // [$queues, $time, $runAt] = $this->remember($query);

        if (request()->hasHeader('X-Livewire')) {
            $this->dispatch('queues-chart-update', queues: $queues);
        }

        $time = 0;
        $runAt = 0;

        // dd($queues);

        return View::make('pulse::livewire.queues', [
            'queues' => $queues,
            'showConnection' => $queues->keys()->map(fn ($queue) => Str::before($queue, ':'))->unique()->count() > 1,
            'time' => $time,
            'runAt' => $runAt,
            'config' => Config::get('pulse.recorders.'.Jobs::class),
        ]);
    }
}
