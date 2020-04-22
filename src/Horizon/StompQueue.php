<?php

namespace Norgul\Stomp\Horizon;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\JobId;
use Laravel\Horizon\JobPayload;
use Norgul\Stomp\Queue\Jobs\StompJob;
use Norgul\Stomp\Queue\StompQueue as BaseStompQueue;

class StompQueue extends BaseStompQueue
{
    /**
     * The job that last pushed to queue via the "push" method.
     *
     * @var object|string
     */
    protected $lastPushed;

    /**
     * Get the number of queue jobs that are ready to process.
     *
     * @param string|null $queue
     * @return int
     */
    public function readyNow($queue = null)
    {
        $size = $this->size($queue);
        Log::info('[STOMP] Queue size: ' . $size);
        return $size;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param object|string $job
     * @param mixed $data
     * @param string|null $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;
        return parent::push($job, $data, $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param string $payload
     * @param string $queue
     * @param array $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $payload = (new JobPayload($payload))->prepare($this->lastPushed)->value;

        return tap(parent::pushRaw($payload, $queue, $options), function () use ($queue, $payload) {
            $this->event($this->getQueue($queue), new JobPushed($payload));
        });
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string $job
     * @param mixed $data
     * @param string $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = (new JobPayload($this->createPayload($job, $queue, $data)))->prepare($job)->value;

        return tap(parent::pushRaw($payload, $queue, ['delay' => $this->secondsUntil($delay)]), function () use ($payload, $queue) {
            $this->event($this->getQueue($queue), new JobPushed($payload));
        });
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        Log::info('[STOMP] Popping a job from queue...');

        return tap(parent::pop($queue), function ($result) use ($queue) {
            if ($result instanceof StompJob) {
                $this->event($this->getQueue($queue), new JobReserved($result->getRawBody()));
            }
        });
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @param string $queue
     * @param StompJob $job
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function deleteReserved($queue, $job)
    {
        Log::info('[STOMP] Deleting a reserved job: ' . print_r(['job' => $job, 'queue' => $queue], true));
        $this->event($this->getQueue($queue), new JobDeleted($job, $job->getRawBody()));
    }

    /**
     * Fire the given event if a dispatcher is bound.
     *
     * @param string $queue
     * @param mixed $event
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function event($queue, $event)
    {
        Log::info('[STOMP] Firing event: ' . print_r(['event' => $event, 'queue' => $queue], true));

        if ($this->container && $this->container->bound(Dispatcher::class)) {
            $connectionName = $this->getConnectionName();
            Log::info('[STOMP] Dispatching event: ' . print_r(['connectionName' => $connectionName, 'queue' => $queue], true));
            $this->container->make(Dispatcher::class)->dispatch(
                $event->connection($connectionName)->queue($queue)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getRandomId(): string
    {
        return JobId::generate();
    }
}
