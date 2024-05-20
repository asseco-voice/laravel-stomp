<?php

namespace Asseco\Stomp\Queue\Jobs;

use Asseco\Stomp\Queue\Stomp\Config;
use Asseco\Stomp\Queue\StompQueue;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Jobs\JobName;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;
use Throwable;

class StompJob extends Job implements JobContract
{
    protected const DEFAULT_TRIES = 2;

    protected StompQueue $stompQueue;
    protected Frame $frame;
    protected LoggerInterface $log;
    protected string $session;

    protected array $payload;

    public function __construct(Container $container, StompQueue $stompQueue, Frame $frame, string $queue)
    {
        $this->container = $container;
        $this->stompQueue = $stompQueue;
        $this->frame = $frame;
        $this->connectionName = 'stomp';
        $this->queue = $queue;
        $this->session = $this->stompQueue->client->getClient()->getSessionId();

        $this->log = app('stompLog');

        $this->payload = $this->payload();
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        // Even though payload() decodes it again, this must be left as is because
        // job failure calls this method and we need headers in DB table as well.
        $body = json_decode($this->frame->getBody(), true);
        $headers = [$this->stompQueue::HEADERS_KEY => $this->headers()];

        return json_encode(array_merge($body, $headers));
    }

    public function headers(): array
    {
        return $this->frame->getHeaders();
    }

    /**
     * Overridden for configurable automatic max tries.
     * Get the number of times to attempt a job.
     *
     * @return int|null
     */
    public function maxTries()
    {
        if (Config::get('auto_tries')) {
            return self::DEFAULT_TRIES;
        }

        return parent::maxTries();
    }

    public function shouldFailOnTimeout()
    {
        return Config::get('fail_on_timeout') ?: parent::shouldFailOnTimeout();
    }

    public function timeout()
    {
        return Config::get('timeout') ?: parent::timeout();
    }

    /**
     * Overridden to include 2 fallbacks to standard Laravel jobs: one for external job ID's, other if that fails as well.
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return Arr::get($this->payload, 'uuid') ?: Arr::get($this->payload, $this->stompQueue::HEADERS_KEY . '.message-id') ?: Str::uuid();
    }

    /**
     * Fire the job.
     *
     * @return void
     */
    public function fire()
    {
        $this->log->info("$this->session [STOMP] Executing event...");
        $this->isNativeLaravelJob() ? $this->fireLaravelJob() : $this->fireExternalJob();
        $this->ackIfNecessary();
    }

    protected function isNativeLaravelJob(): bool
    {
        $job = Arr::get($this->payload, 'job');

        return $job && str_contains($job, 'CallQueuedHandler@call');
    }

    protected function laravelJobClassExists(): bool
    {
        $eventClassName = Arr::get($this->payload, 'displayName');
        if ($eventClassName) {
            return class_exists($eventClassName);
        } else {
            $command = Arr::get($this->payload, 'data.command');
            $command = $command ?? unserialize($command);
            /** @var BroadcastEvent $command */
            if ($command & $command->event && class_exists(get_class($command->event))) {
                return true;
            }
        }

        return false;
    }

    protected function fireLaravelJob(): void
    {
        if ($this->laravelJobClassExists()) {
            [$class, $method] = JobName::parse($this->payload['job']);
            ($this->instance = $this->resolve($class))->{$method}($this, $this->payload['data']);
        } else {
            $this->log->error("$this->session [STOMP] Laravel job class does not exist!");
        }
    }

    protected function fireExternalJob(): void
    {
        Event::dispatch($this->getName(), $this->payload);
    }

    /**
     * Get the name of the queued job class. Fallback if it is an external event.
     *
     * @return string
     */
    public function getName()
    {
        return Arr::get($this->payload, 'job') ?: $this->getExternalEventName();
    }

    protected function getExternalEventName(): string
    {
        $jobName = 'event';
        $subscribedTo = $this->getSubscriptionName();

        if ($subscribedTo) {
            $jobName = 'stomp.' . str_replace('::', '.', $subscribedTo);
        }

        return $jobName;
    }

    protected function getSubscriptionName(): string
    {
        return $this->stompQueue->client->getSubscriptions()->getSubscription($this->frame)->getDestination();
    }

    /**
     * Delete the job from the queue.
     *
     * @return void
     */
    public function delete()
    {
        $this->log->info("$this->session [STOMP] Deleting a message from queue: " . print_r([
            'queue' => $this->queue,
            'message' => $this->frame,
        ], true));

        parent::delete();
    }

    /**
     * Release the job back into the queue.
     *
     * @param  int  $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $payload = $this->createStompPayload($delay);

        $this->stompQueue->pushRaw($payload, $this->queue, []);
    }

    protected function createStompPayload(int $delay): Message
    {
        $attempts = $this->attempts() + 1;
        Arr::set($this->payload, 'attempts', $attempts);

        $backoff = Config::get('auto_backoff') ? $this->getBackoff($attempts) : $delay;
        Arr::set($this->payload, 'backoff', $backoff);

        $delayHeader = $this->stompQueue->makeDelayHeader($backoff);
        $headers = array_merge($this->headers(), $delayHeader);
        $headers = $this->stompQueue->forgetHeadersForRedelivery($headers);

        return new Message(json_encode($this->payload), $headers);
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return Arr::get($this->payload, 'attempts', 0);
    }

    protected function getBackoff(int $attempts): int
    {
        return pow($attempts, Config::get('backoff_multiplier'));
    }

    /**
     * Process an exception that caused the job to fail.
     *
     * @param  Throwable|null  $e
     * @return void
     */
    protected function failed($e)
    {
        $this->ackIfNecessary();

        // External events don't have failed method to call.
        if (!$this->payload || !$this->isNativeLaravelJob()) {
            return;
        }

        [$class, $method] = JobName::parse($this->payload['job']);

        try {
            if (method_exists($this->instance = $this->resolve($class), 'failed')) {
                $this->instance->failed($this->payload['data'], $e, $this->payload['uuid']);
            }
        } catch (\Exception $e) {
            Log::error('Exception in job failing: ' . $e->getMessage());
        }
    }

    protected function ackIfNecessary()
    {
        $this->stompQueue->ackLastFrameIfNecessary();
    }
}
