<?php

namespace Laravel\Pulse\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Query\Builder;
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
        $interval = $this->periodAsInterval();

        $period = $interval->totalSeconds;

        $maxDataPoints = 60;
        $secondsPerPeriod = ($interval->totalSeconds / $maxDataPoints);
        $currentBucket = (int) floor($now->timestamp / $secondsPerPeriod) * $secondsPerPeriod;
        $firstBucket = $currentBucket - (($maxDataPoints - 1) * $secondsPerPeriod);

        // ['pulse_demo' => ['cpu' => ['date' => 'value']]]

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
                    // ->selectRaw("\"$type\" AS `type`")
                    ->addSelect('type')
                    ->addSelect('key')
                    ->selectRaw('ROUND(AVG(`value`)) AS `value`')
                    ->whereIn('type', ['cpu', 'memory'])
                    ->where('timestamp', '>=', max($firstBucket, $latestAggregatedBucket + $secondsPerPeriod))
                    ->where('timestamp', '<', $currentBucket)
                    ->groupBy('key', 'bucket', 'type')
                // ->get()->dd();
            );
        }

        $padding = collect()
            ->range(0, 59)
            ->mapWithKeys(fn ($i) => [Carbon::createFromTimestamp($firstBucket + $i * $secondsPerPeriod)->toDateTimeString() => null]);

        $serverReadings = DB::table('pulse_aggregates')
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
            )->dump();

        // select `key`, max(`timestamp`) as `timestamp`, (select `value` from `pulse_entries` as t1 where `t1`.`key` = `t`.`key` order by `timestamp` desc limit 1) as `value`
        // from `pulse_entries` as t
        // where `type` = 'storage'

        // $storage = DB::table('pulse_entries', as: 't')
        //     ->addSelect('key')
        //     ->selectRaw('max(`timestamp`) AS `timestamp`')
        //     ->selectSub(fn (Builder $query) => $query
        //         ->select('value')
        //         ->from('pulse_entries', as: 't1')
        //         ->whereColumn('t.key', 't1.key')
        //         ->latest('timestamp')
        //         ->limit(1),
        //     'value')
        //     ->where('type', 'storage')
        //     ->groupBy('key')
        //     ->dumpRawSql()
        //     ->get()->dd();

        // DB::table('pulse_entries')
        //     ->selectRaw('FLOOR(`timestamp` / ?) * ? AS `bucket`', [$secondsPerPeriod, $secondsPerPeriod])
        //     ->selectRaw("$period AS `period`")
        //     // ->selectRaw("\"$type\" AS `type`")
        //     ->addSelect('type')
        //     ->addSelect('key')
        //     ->selectRaw('ROUND(AVG(`value`)) AS `value`')
        //     ->where('type', 'storage')
        //     ->where('timestamp', '>=', max($firstBucket, $latestAggregatedBucket + $secondsPerPeriod))
        //     ->where('timestamp', '<', $currentBucket)
        //     ->groupBy('key', 'bucket', 'type')->get()->dd();

        // DB::table('pulse_entries')
        //     // ->selectRaw('FLOOR(`timestamp` / ?) * ? AS `bucket`', [$secondsPerPeriod, $secondsPerPeriod])
        //     // ->selectRaw("$period AS `period`")
        //     // ->selectRaw("\"$type\" AS `type`")
        //     // ->addSelect('type')
        //     ->addSelect('key')
        //     ->selectRaw('max(`timestamp`) AS `timestamp`')
        //     ->where('type', 'storage')
        //     // ->where('timestamp', '>=', max($firstBucket, $latestAggregatedBucket + $secondsPerPeriod))
        //     // ->where('timestamp', '<', $currentBucket)
        //     ->groupBy('key')->get()->dd();

        DB::table('pulse_entries')
            ->joinSub(
                DB::table('pulse_entries')
                    ->addSelect('key')
                    ->selectRaw('max(`timestamp`) as `timestamp`')
                    ->where('type', 'storage')
                    ->groupBy('key'),
                'grouped',
                fn ($join) => $join
                    ->on('pulse_entries.key', '=', 'grouped.key')
                // ->on('pulse_entries.timestamp', '=', 'grouped.timestamp')
            )
            ->dumpRawSql()
            ->get()->dd();
        //     ->map(function ($row) {
        //         if ($row->type === 'storage') {
        //             $row->directory = Str::afterLast($row->key, ':');
        //             $row->key = Str::beforeLast($row->key, ':');
        //         }

        //         return $row;
        //     })
        //     ->groupBy('key')
        //     ->map(function ($current, $key) use ($serverReadings, $now) {
        //         return (object) [
        //             'name' => $key,
        //             'slug' => Str::slug($key),
        //             'updated_at' => $updatedAt = Carbon::createFromTimestamp($current->first()->timestamp),
        //             'recently_reported' => (bool) $updatedAt->isAfter($now->subSeconds(30)),
        //             'cpu' => [
        //                 'current' => $current->firstWhere('type', 'cpu')->value,
        //                 'history' => $serverReadings->get($key)?->get('cpu') ?? [],
        //             ],
        //             'memory' => [
        //                 'current' => $current->firstWhere('type', 'memory')->value,
        //                 'history' => $serverReadings->get($key)?->get('memory') ?? [],
        //             ],
        //             'storage' => $current
        //                 ->where('type', 'storage')
        //                 ->mapWithKeys(fn ($row) => [$row->directory => $row->value]),
        //         ];
        //     });

        // $keys = DB::table('pulse_entries')
        //     ->select('timestamp', 'key', 'value')
        //     ->where('type', 'cpu')

        // $keys2 = DB::table('pulse_entries')
        //     ->select('timestamp', 'key', 'value')
        //     ->where('type', 'memory')
        //     ->latest('timestamp')
        //     ->pluck('value', 'key');

        // $keys3 = DB::table('pulse_entries')
        //     ->where('type', 'storage')
        //     ->latest('timestamp')
        //     ->pluck('value', 'key');
        // dd($keys, $keys2, $keys3);

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
