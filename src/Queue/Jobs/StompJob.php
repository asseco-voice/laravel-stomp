<?php

namespace Norgul\Stomp\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Norgul\Stomp\Queue\StompQueue;
use Stomp\Transport\Frame;

class StompJob extends Job implements JobContract
{
    /**
     * The Stomp instance.
     */
    private StompQueue $stompQueue;

    /**
     * The Stomp frame instance.
     * Message is just Frame with SEND command
     */
    protected Frame $frame;

    public function __construct(Container $container, StompQueue $stompQueue, Frame $frame)
    {
        $this->container = $container;
        $this->stompQueue = $stompQueue;
        $this->frame = $frame;

        Log::info('[STOMP] Creating a job with following data: ' . print_r([
                'queue'     => $stompQueue->queue,
                'frameBody' => $frame->body,
            ], true));
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        $jobId = Arr::get($this->payload(), 'id', null);
        Log::info("[STOMP] Job ID: {$jobId}");
        return $jobId;
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        Log::info("[STOMP] Raw frame body: {$this->frame->body}");
        return $this->frame->body;
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        $attempts = Arr::get($this->payload(), 'attempts', 1);
        Log::info("[STOMP] Attempts: {$attempts}");
        return $attempts;
    }

    /**
     * Delete the job from the queue.
     *
     * @return void
     */
    public function delete()
    {
        parent::delete();
        $this->stompQueue->deleteMessage($this->getQueue(), $this->frame);
    }

    /**
     * Release the job back into the queue.
     *
     * @param int $delay
     * @return void
     */
    public function release($delay = 0)
    {
        Log::info("[STOMP] Releasing...");
        parent::release($delay);
        $this->recreateJob($delay);
    }

    /**
     * Release a pushed job back onto the queue.
     *
     * @param int $delay
     * @return void
     */
    protected function recreateJob($delay)
    {
        Log::info("[STOMP] Recreating...");
        $payload = $this->payload();
        Arr::set($payload, 'attempts', Arr::get($payload, 'attempts', 1) + 1);

        $this->stompQueue->recreate(json_encode($payload), $this->getQueue(), $delay);
    }


    /**
     * Get the name of the queued job class.
     *
     * @return string
     */
    public function getName()
    {
        $jobName = Arr::get($this->payload(), 'job');
        Log::info("[STOMP] Job name: {$jobName}");
        return $jobName;
    }

    /**
     * Get the name of the queue the job belongs to.
     *
     * @return string
     */
    public function getQueue()
    {
        $jobQueue = Arr::get($this->payload(), 'queue');
        Log::info("[STOMP] Job queue name: {$jobQueue}");
        return $jobQueue;
    }
}
