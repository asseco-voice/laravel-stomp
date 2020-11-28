<?php

namespace Asseco\Stomp\Horizon\Listeners;

use Asseco\Stomp\Queue\Jobs\StompJob;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\JobFailed;

class StompFailedEvent
{
    /**
     * The event dispatcher implementation.
     *
     * @var Dispatcher
     */
    public Dispatcher $events;

    /**
     * Create a new listener instance.
     *
     * @param Dispatcher $events
     * @return void
     */
    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    /**
     * Handle the event.
     *
     * @param LaravelJobFailed $event
     * @return void
     */
    public function handle(LaravelJobFailed $event): void
    {
        Log::error('[STOMP] Job failed: ' . print_r($event->exception, true));

        if (!$event->job instanceof StompJob) {
            return;
        }

        $this->events->dispatch((new JobFailed(
            $event->exception, $event->job, $event->job->getRawBody()
        ))->connection($event->connectionName)->queue($event->job->getQueue()));
    }
}
