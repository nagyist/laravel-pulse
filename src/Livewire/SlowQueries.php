<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Queries\SlowQueries as SlowQueriesQuery;
use Laravel\Pulse\Recorders\SlowQueries as SlowQueriesRecorder;
use Livewire\Attributes\Lazy;

#[Lazy]
class SlowQueries extends Card
{
    use Concerns\HasPeriod, Concerns\ResolvesQueries;

    /**
     * Render the component.
     */
    public function render(SlowQueriesQuery $query): Renderable
    {
        [$slowQueries, $time, $runAt] = $this->remember($query);

        return View::make('pulse::livewire.slow-queries', [
            'time' => $time,
            'runAt' => $runAt,
            'config' => Config::get('pulse.recorders.'.SlowQueriesRecorder::class),
            'slowQueries' => $slowQueries,
        ]);
    }
}
