<?php

namespace Laravel\Pulse\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Events\Beat;
use RuntimeException;

/**
 * @internal
 */
class SystemStats
{
    /**
     * The table to record to.
     */
    public string $table = 'pulse_system_stats';

    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = Beat::class;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Repository $config,
    ) {
        //
    }

    /**
     * Record the system stats.
     */
    public function record(Beat $event): ?Entry
    {
        if ($event->time->second % 15 !== 0) {
            return null;
        }

        $server = $this->config->get('pulse.recorders.'.self::class.'.server_name');
        $slug = Str::slug($server);

        $memoryTotal = match (PHP_OS_FAMILY) {
            'Darwin' => intval(`sysctl hw.memsize | grep -Eo '[0-9]+'` / 1024 / 1024),
            'Linux' => intval(`cat /proc/meminfo | grep MemTotal | grep -E -o '[0-9]+'` / 1024),
            default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
        };

        $memoryUsed = match (PHP_OS_FAMILY) {
            'Darwin' => $memoryTotal - intval(intval(`vm_stat | grep 'Pages free' | grep -Eo '[0-9]+'`) * intval(`pagesize`) / 1024 / 1024), // MB
            'Linux' => $memoryTotal - intval(`cat /proc/meminfo | grep MemAvailable | grep -E -o '[0-9]+'` / 1024), // MB
            default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
        };

        $cpu = match (PHP_OS_FAMILY) {
            'Darwin' => (int) `top -l 1 | grep -E "^CPU" | tail -1 | awk '{ print $3 + $5 }'`,
            'Linux' => (int) `top -bn1 | grep '%Cpu(s)' | tail -1 | grep -Eo '[0-9]+\.[0-9]+' | head -n 4 | tail -1 | awk '{ print 100 - $1 }'`,
            default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
        };

        DB::table('pulse_values')->updateOrInsert([
            'key' => 'system:'.$slug,
        ], ['value' => json_encode([
            'name' => $server,
            'timestamp' => $event->time->timestamp,
            'memory_used' => $memoryUsed,
            'memory_total' => $memoryTotal,
            'cpu' => $cpu,
            'storage' => collect($this->config->get('pulse.recorders.'.self::class.'.directories')) // @phpstan-ignore argument.templateType
                ->map(fn (string $directory) => [
                    'directory' => $directory,
                    'total' => $total = intval(round(disk_total_space($directory) / 1024 / 1024)), // MB
                    'used' => intval(round($total - (disk_free_space($directory) / 1024 / 1024))), // MB
                ])
                ->toArray(),
        ])]);

        DB::table('pulse_entries')->insert([
            'timestamp' => $event->time->timestamp,
            'type' => 'cpu',
            'key' => $slug,
            'value' => $cpu,
        ]);

        DB::table('pulse_entries')->insert([
            'timestamp' => $event->time->timestamp,
            'type' => 'memory',
            'key' => $slug,
            'value' => $memoryUsed,
        ]);

        return null;

        return new Entry($this->table, [
            'date' => $event->time->toDateTimeString(),
            'server' => $this->config->get('pulse.recorders.'.self::class.'.server_name'),
            ...match (PHP_OS_FAMILY) {
                'Darwin' => [
                    'cpu_percent' => (int) `top -l 1 | grep -E "^CPU" | tail -1 | awk '{ print $3 + $5 }'`,
                    'memory_total' => $memoryTotal = intval(`sysctl hw.memsize | grep -Eo '[0-9]+'` / 1024 / 1024), // MB
                    'memory_used' => $memoryTotal - intval(intval(`vm_stat | grep 'Pages free' | grep -Eo '[0-9]+'`) * intval(`pagesize`) / 1024 / 1024), // MB
                ],
                'Linux' => [
                    'cpu_percent' => (int) `top -bn1 | grep '%Cpu(s)' | tail -1 | grep -Eo '[0-9]+\.[0-9]+' | head -n 4 | tail -1 | awk '{ print 100 - $1 }'`,
                    'memory_total' => $memoryTotal = intval(`cat /proc/meminfo | grep MemTotal | grep -E -o '[0-9]+'` / 1024), // MB
                    'memory_used' => $memoryTotal - intval(`cat /proc/meminfo | grep MemAvailable | grep -E -o '[0-9]+'` / 1024), // MB
                ],
                default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
            },
            'storage' => collect($this->config->get('pulse.recorders.'.self::class.'.directories')) // @phpstan-ignore argument.templateType, argument.templateType
                ->map(fn (string $directory) => [
                    'directory' => $directory,
                    'total' => $total = intval(round(disk_total_space($directory) / 1024 / 1024)), // MB
                    'used' => intval(round($total - (disk_free_space($directory) / 1024 / 1024))), // MB
                ])
                ->toJson(),
        ]);
    }
}
