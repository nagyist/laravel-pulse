<?php

namespace Laravel\Pulse\Http\Livewire;

use Illuminate\Support\Facades\Queue;
use Laravel\Pulse\Contracts\ShouldNotReportUsage;
use Livewire\Component;

class Queues extends Component implements ShouldNotReportUsage
{
    /**
     * The width of the component.
     *
     * @var string
     */
    public $width;

    /**
     * Handle the mount event.
     *
     * @param  string  $width
     * @return void
     */
    public function mount($width = '1/2')
    {
        $this->width = $width;
    }

    public function render()
    {
        return view('pulse::livewire.queues', [
            'queues' => collect(config('pulse.queues'))->map(fn ($queue) => [
                'queue' => $queue,
                'size' => Queue::size($queue),
                'failed' => collect(app('queue.failer')->all())->filter(fn ($job) => $job->queue === $queue)->count(),
            ]),
        ]);
    }
}
