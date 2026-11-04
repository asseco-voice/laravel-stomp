<?php

namespace Asseco\Stomp\Queue\Jobs;

use Asseco\Stomp\Queue\Contracts\RetriesOnStomp;
use Asseco\Stomp\Queue\Exceptions\RequeueFailedException;
use Asseco\Stomp\Queue\Stomp\Config;
use Asseco\Stomp\Queue\StompQueue;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Jobs\JobName;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;
use Throwable;

class StompJob extends Job implements JobContract
{
    protected const DEFAULT_TRIES = 2;

    /**
     * Wrappers whose payload displayName is the class they wrap. A message from another service on a shared
     * address can wrap a class that does not exist here; it is skipped rather than failing to unserialize.
     */
    protected const WRAPPED_CLASS_DISPLAY_NAMES = [
        BroadcastEvent::class,
        CallQueuedListener::class,
        SendQueuedMailable::class,
        SendQueuedNotifications::class,
    ];

    protected StompQueue $stompQueue;
    protected Frame $frame;
    protected LoggerInterface $log;
    protected string $session;

    protected array $payload;

    /** Whether the job opted in with RetriesOnStomp; decided once, from the payload. */
    protected ?bool $retriesOnStomp = null;

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
        $body = json_decode($this->frame->getBody(), true, 1024);
        $headers = [$this->stompQueue::HEADERS_KEY => $this->headers()];

        return json_encode(array_merge(is_array($body) ? $body : [], $headers), depth: 1024);
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
        if ($this->retriesOnStomp()) {
            return parent::maxTries();
        }

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
    }

    /**
     * Whether the job opted in to Laravel retries (RetriesOnStomp): the queued job class, or for a queued
     * listener the listener class. Read from the payload, without unserializing the command.
     */
    public function retriesOnStomp(): bool
    {
        return $this->retriesOnStomp ??= $this->resolveRetriesOnStomp();
    }

    protected function resolveRetriesOnStomp(): bool
    {
        if (!$this->isNativeLaravelJob()) {
            return false;
        }

        $class = Arr::get($this->payload, 'data.commandName');

        if ($class === CallQueuedListener::class) {
            // CallQueuedListener::displayName() is the listener class
            $class = Arr::get($this->payload, 'displayName');
        }

        return is_string($class) && is_a($class, RetriesOnStomp::class, true);
    }

    protected function isNativeLaravelJob(): bool
    {
        $job = Arr::get($this->payload, 'job');

        return $job && str_contains($job, 'CallQueuedHandler@call');
    }

    /**
     * Whether the job can run here, decided from the class of its command (data.commandName), as Laravel does,
     * falling back to the job handler class. Not from displayName: a job's displayName() can be anything
     * (QueuedCommand's is the artisan command name), and only the wrappers above put a class name there.
     */
    protected function laravelJobClassExists(): bool
    {
        $commandName = Arr::get($this->payload, 'data.commandName');

        if (!is_string($commandName)) {
            [$handler] = JobName::parse($this->payload['job']);

            return class_exists($handler);
        }

        if (!class_exists($commandName)) {
            return false;
        }

        if (in_array($commandName, self::WRAPPED_CLASS_DISPLAY_NAMES, true)) {
            $wrapped = Arr::get($this->payload, 'displayName');

            return is_string($wrapped) && class_exists($wrapped);
        }

        return true;
    }

    protected function fireLaravelJob(): void
    {
        if ($this->laravelJobClassExists()) {
            [$class, $method] = JobName::parse($this->payload['job']);
            ($this->instance = $this->resolve($class))->{$method}($this, $this->payload['data'] ?? []);
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
        if ($this->retriesOnStomp()) {
            $this->releaseForNextAttempt((int) $delay);

            return;
        }

        parent::release($delay);

        if (Config::get('fail_job_requeue')) {
            $payload = $this->createStompPayload($delay);
            $this->stompQueue->pushRaw($payload, $this->queue, []);
        }
    }

    /**
     * Re-send an opted-in job for its next attempt, to the queue it came from, delayed by the broker for $delay
     * seconds (the job's own backoff, or the delay it released itself with). If the broker does not take it, the
     * job is failed so that failed() runs and a failed_jobs row is written, rather than being lost.
     */
    protected function releaseForNextAttempt(int $delay): void
    {
        if ($this->isReleased() || $this->hasFailed()) {
            return;
        }

        parent::release($delay);

        $nextAttempt = $this->attempts() + 1;
        $cause = null;

        try {
            $sent = $this->stompQueue->pushRaw($this->createNextAttemptPayload($nextAttempt, $delay), $this->queue);
        } catch (Throwable $e) {
            $sent = false;
            $cause = $e;
        }

        if ($sent) {
            return;
        }

        $this->log->error("$this->session [STOMP] Could not requeue the job for attempt $nextAttempt, failing it.");

        $this->fail(new RequeueFailedException(sprintf(
            'Could not requeue %s for attempt %d on queue %s%s',
            $this->resolveName(),
            $nextAttempt,
            $this->queue,
            $cause ? ': ' . $cause->getMessage() : '.'
        ), 0, $cause));
    }

    protected function createNextAttemptPayload(int $attempt, int $delay): Message
    {
        $payload = Arr::except($this->payload, $this->stompQueue::HEADERS_KEY);
        $payload['attempts'] = $attempt;

        $headers = $this->stompQueue->forgetHeadersForRedelivery($this->headers());
        Arr::forget($headers, StompQueue::AMQ_SCHEDULED_DELAY);

        if ($delay > 0) {
            $headers = array_merge($headers, $this->stompQueue->makeDelayHeader($delay));
        }

        $body = json_encode($payload, depth: 1024);

        if ($body === false) {
            throw new RuntimeException('Unable to JSON encode the payload: ' . json_last_error_msg());
        }

        return new Message($body, $headers);
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

        return new Message(json_encode($this->payload, depth: 1024), $headers);
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        if ($this->retriesOnStomp()) {
            // Laravel's convention: the first run is attempt 1; a re-send carries the number of its attempt.
            return max(1, (int) Arr::get($this->payload, 'attempts', 0));
        }

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
        // External events don't have failed method to call.
        if (!$this->payload || !$this->isNativeLaravelJob()) {
            return;
        }

        [$class, $method] = JobName::parse($this->payload['job']);

        try {
            if (method_exists($this->instance = $this->resolve($class), 'failed')) {
                $this->retriesOnStomp()
                    // with the job, so the command's failed() sees it (attempts(), batch, ...), as Laravel does
                    ? $this->instance->failed($this->payload['data'] ?? [], $e, $this->payload['uuid'], $this)
                    : $this->instance->failed($this->payload['data'] ?? [], $e, $this->payload['uuid']);
            }
        } catch (\Exception $e) {
            $this->log->error('Exception in job failing: ' . $e->getMessage());
        }
    }
}
