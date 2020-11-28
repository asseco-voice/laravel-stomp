<?php

namespace Asseco\Stomp\Horizon;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\JobPayload;
use Asseco\Stomp\Queue\Jobs\StompJob;
use Asseco\Stomp\Queue\StompQueue as BaseStompQueue;

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
        return $this->size($queue);
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
     * @param ?string $queue
     * @param array $options
     * @return mixed
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $payload = (new JobPayload($payload))->prepare($this->lastPushed)->value;

        return tap(parent::pushRaw($payload, $queue, $options), function () use ($queue, $payload) {
            $this->event($this->getReadQueues($queue), new JobPushed($payload));
        });
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string $job
     * @param mixed $data
     * @param ?string $queue
     * @return mixed
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = (new JobPayload($this->createPayload($job, $queue, $data)))->prepare($job)->value;

        return tap(parent::pushRaw($payload, $queue, ['delay' => $this->secondsUntil($delay)]), function () use ($payload, $queue) {
            $this->event($this->getReadQueues($queue), new JobPushed($payload));
        });
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function pop($queue = null)
    {
        return tap(parent::pop($queue), function ($job) use ($queue) {
            if ($job instanceof StompJob) {
                $this->event($this->getReadQueues($queue), new JobDeleted($job, $job->getRawBody()));
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
        $this->event($this->getReadQueues($queue), new JobDeleted($job, $job->getRawBody()));
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
        if ($this->container && $this->container->bound(Dispatcher::class)) {
            /**
             * @var JobPushed $connection
             * @var JobReserved $event
             */
            $connection = $event->connection($this->getConnectionName());

            $this->container->make(Dispatcher::class)->dispatch(
                $connection->queue($queue)
            );
        }
    }
}
