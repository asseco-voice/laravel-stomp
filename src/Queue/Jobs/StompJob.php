<?php

namespace Norgul\Stomp\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Norgul\Stomp\Queue\StompQueue;
use Stomp\Transport\Message;

class StompJob extends Job implements JobContract
{
    private StompQueue $stompQueue;

    private Message $message;

    /**
     * The JSON decoded version of "$message".
     *
     * @var array
     */
    protected $decoded;

    public function __construct(Container $container, StompQueue $stompQueue, Message $message, string $connectionName, string $queue)
    {
        $this->container = $container;
        $this->stompQueue = $stompQueue;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->decoded = $this->payload();
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return $this->decoded['id'] ?? null;
    }

    /**
     * Get the raw body of the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->message->getBody();
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        $headers = $this->message->getHeaders();

        if (!$headers['application_headers']) {
            return null;
        }

        // TODO: check this out
        //$attempts = (int) Arr::get($data, 'laravel.attempts', 0);

        return 1;
    }
}
