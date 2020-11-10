<?php

namespace Voice\Stomp\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;
use Voice\Stomp\Queue\Contracts\HasHeaders;
use Voice\Stomp\Queue\Contracts\HasRawData;
use Voice\Stomp\Queue\Jobs\StompJob;
use Voice\Stomp\Queue\Stomp\ClientWrapper;
use Voice\Stomp\Queue\Stomp\ConfigWrapper;

class StompQueue extends Queue implements QueueInterface
{
    public const AMQ_QUEUE_SEPARATOR = '::';
    public const HEADERS_KEY = '_headers';

    /**
     * Queue name.
     */
    public string $readQueues;

    /**
     * Queue to write to.
     * @var string
     */
    public string $writeQueue;

    /**
     * List of queues already subscribed to. Preventing multiple same subscriptions.
     * @var array
     */
    protected array $subscribedTo = [];

    /**
     * Stomp instance from stomp-php repo.
     */
    public StatefulStomp $client;

    protected LoggerInterface $log;

    public function __construct(ClientWrapper $stompClient)
    {
        $this->readQueues = $this->appendQueue(ConfigWrapper::get('read_queues'));
        $this->writeQueue = ConfigWrapper::get('write_queue');
        $this->client = $stompClient->client;

        $this->log = App::make('stompLog');
    }

    /**
     * Append queue name to topic/address to avoid random hashes in broker.
     *
     * @param string $queues
     * @return string
     */
    protected function appendQueue(string $queues): string
    {
        $default = ConfigWrapper::get('default_queue');

        return implode(';', array_map(function ($queue) use ($default) {
            if (!str_contains($queue, self::AMQ_QUEUE_SEPARATOR)) {
                return $queue . self::AMQ_QUEUE_SEPARATOR . $default . "_" . Str::uuid();
            }

            return $queue;
        }, $this->parseDelimitedQueues($queues)));
    }

    /**
     * Get the size of the queue.
     *
     * @param string|null $queue
     * @return int
     */
    public function size($queue = null)
    {
        // Stomp library doesn't have this functionality
        return 0;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string|object $job
     * @param mixed $data
     * @param string|null $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param DateTimeInterface|DateInterval|int $delay
     * @param string|object $job
     * @param mixed $data
     * @param string|null $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data, $queue), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param string $payload
     * @param string|null $queue
     * @param array $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (!$payload instanceof Message) {
            $payload = $this->wrapStompMessage($payload);
        }

        $writeQueue = $queue ?: $this->getWriteQueue();

        /**
         * @var $payload Message
         */
        $this->log->info('[STOMP] Pushing stomp payload to queue: ' . print_r([
                'body'    => $payload->getBody(),
                'headers' => $payload->getHeaders(),
                'queue'   => $writeQueue,
            ], true));

        return $this->client->send($writeQueue, $payload);
    }

    protected function wrapStompMessage(string $payload): Message
    {
        $decoded = json_decode($payload, true);
        $body = Arr::except($decoded, self::HEADERS_KEY);
        $body = $this->addMissingUuid($body);
        $headers = Arr::get($decoded, self::HEADERS_KEY, []);
        $headers = $this->forgetHeadersForRedelivery($headers);

        return new Message(json_encode($body), $headers);
    }

    /**
     * Overridden to prevent double json encoding/decoding
     * Create a payload string from the given job and data.
     *
     * @param $job
     * @param $queue
     * @param string $data
     * @return Message
     */
    protected function createPayload($job, $queue, $data = '')
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $payload = $this->createPayloadArray($job, $queue, $data);
        $payload = $this->addMissingUuid($payload);
        $headers = $this->getHeaders($job);
        $headers = $this->forgetHeadersForRedelivery($headers);

        $message = new Message(json_encode($payload), $headers);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: ' . json_last_error()
            );
        }

        return $message;
    }

    /**
     * Overridden to support raw data
     * Create a payload array from the given job and data.
     *
     * @param object|string $job
     * @param string $queue
     * @param string $data
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        if ($this->hasEvent($job) && $job->event instanceof HasRawData) {
            return $job->event->getRawData();
        }

        if ($job instanceof HasRawData) {
            return $job->getRawData();
        }

        return parent::createPayloadArray($job, $queue, $data);
    }

    protected function addMissingUuid(array $payload): array
    {
        if (!Arr::has($payload, 'uuid')) {
            $payload['uuid'] = (string) Str::uuid();
        }

        return $payload;
    }

    protected function getHeaders($job)
    {
        if ($this->hasEvent($job) && $job->event instanceof HasHeaders) {
            return $job->event->getHeaders();
        }

        if ($job instanceof HasHeaders) {
            return $job->getHeaders();
        }

        return [];
    }

    protected function hasEvent($job): bool
    {
        return $job instanceof BroadcastEvent && property_exists($job, 'event');
    }

    protected function getWriteQueue()
    {
        $queues = $this->parseDelimitedQueues($this->writeQueue);

        return $queues[0];
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     * @return Job|null
     */
    public function pop($queue = null)
    {
        try {
            $this->client->getClient()->getConnection()->sendAlive();
            $this->subscribeToQueues();
            $frame = $this->client->read();
        } catch (Exception $e) {
            $this->log->error("[STOMP] Stomp failed to read any data from '$queue' queue. " . $e->getMessage());

            return null;
        }

        if (is_null($frame) || !($frame instanceof Frame)) {
            return null;
        }

        $this->log->info('[STOMP] Popping a job (frame) from queue: ' . print_r($frame, true));

        return new StompJob($this->container, $this, $frame, $this->getQueueFromFrame($frame));
    }

    protected function subscribeToQueues(): void
    {
        $queues = $this->parseDelimitedQueues($this->readQueues);

        foreach ($queues as $queue) {
            $alreadySubscribed = in_array($queue, $this->subscribedTo);

            if ($alreadySubscribed) {
                continue;
            }

            $getQueue = $this->getReadQueues($queue);
            $this->client->subscribe($getQueue);
            $this->subscribedTo[] = $getQueue;
        }
    }

    protected function parseDelimitedQueues(string $queue): array
    {
        return explode(';', $queue);
    }

    /**
     * Get the queue or return the default.
     *
     * @param string|null $queue
     * @return string
     */
    protected function getReadQueues(?string $queue): string
    {
        return $queue ?: $this->readQueues;
    }

    protected function getQueueFromFrame(Frame $frame): string
    {
        return $this->client->getSubscriptions()->getSubscription($frame)->getDestination();
    }

    /**
     * Close the connection.
     *
     * @return void
     */
    public function close(): void
    {
        $this->client->getClient()->disconnect();
    }

    public function makeDelayHeader(int $delay): array
    {
        // TODO: remove ActiveMq hard coding
        return ['AMQ_SCHEDULED_DELAY' => $delay * 1000];
    }

    /**
     * If these values are left in the header, it will screw up the whole event redelivery
     * so we need to remove them before sending back to queue.
     *
     * @param array $headers
     * @return array
     */
    public function forgetHeadersForRedelivery(array $headers): array
    {
        // TODO: remove ActiveMq hard coding
        Arr::forget($headers, ['_AMQ_SCHED_DELIVERY', 'content-length']);

        return $headers;
    }
}
