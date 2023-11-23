<?php

namespace Laravel\Pulse\Queries;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval as Interval;
use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Pulse\Support\DatabaseConnectionResolver;
use stdClass;

/**
 * @internal
 */
class Servers
{
    /**
     * Create a new query instance.
     */
    public function __construct(
        protected Repository $config,
        protected DatabaseConnectionResolver $db,
    ) {
        //
    }

    /**
     * Run the query.
     *
     * @return \Illuminate\Support\Collection<string, object{
     *     name: string,
     *     cpu_current: int,
     *     memory_current: int,
     *     memory_total: int,
     *     storage: list<object{
     *         directory: string,
     *         total: int,
     *         used: int,
     *     }>|mixed,
     *     cpu: Collection<string, int>,
     *     memory: Collection<string, int>,
     *     updated_at: \Carbon\CarbonImmutable,
     *     recently_reported: bool,
     * }&stdClass>
     */
    public function __invoke(Interval $interval): Collection
    {
        $now = new CarbonImmutable();

        $servers = $this->db->connection()
            ->table('pulse_values')
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
        $periodInSeconds = (int) $interval->totalSeconds;
        $secondsPerPeriod = ($periodInSeconds / $maxDataPoints);
        $currentBucket = (int) floor($now->timestamp / $secondsPerPeriod) * $secondsPerPeriod;
        $earliestBucketWithinPeriod = $currentBucket - (($maxDataPoints - 1) * $secondsPerPeriod);

        $latestAggregatedBucket = $this->db->connection()
            ->table('pulse_aggregates')
            ->where('period', $periodInSeconds)
            ->whereIn('type', ['cpu', 'memory'])
            ->latest('bucket')
            ->value('bucket') ?? 0;

        $nextBucketToAggregate = $latestAggregatedBucket + $secondsPerPeriod;

        if ($nextBucketToAggregate < $currentBucket) {
            $this->db->connection()
                ->table('pulse_aggregates')
                ->insertUsing(
                    ['bucket', 'period', 'type', 'key', 'value'],
                    $this->db->connection()
                        ->table('pulse_entries')
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
                CarbonImmutable::createFromTimestamp($earliestBucketWithinPeriod + ($i * $secondsPerPeriod))->toDateTimeString() => null,
            ]);

        $this->db->connection()
            ->table('pulse_aggregates')
            ->select('bucket', 'type', 'key', 'value')
            ->where('period', $periodInSeconds)
            ->whereIn('type', ['cpu', 'memory'])
            ->where('bucket', '>=', $earliestBucketWithinPeriod)
            ->orderBy('bucket')
            ->unionAll(
                $this->db->connection()
                    ->table('pulse_entries')
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
                        CarbonImmutable::createFromTimestamp($row->bucket)->toDateTimeString() => (int) $row->value,
                    ])
                ))
            )
            ->each(function ($readings, $server) use (&$servers) {
                $servers[$server]->cpu = $readings['cpu'] ?? collect([]);
                $servers[$server]->memory = $readings['memory'] ?? collect([]);
            });

        return $servers;
    }
}
