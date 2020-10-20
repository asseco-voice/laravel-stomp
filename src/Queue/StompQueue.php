<?php

namespace Voice\Stomp\Queue;

use DateInterval;
use DateTimeInterface;
use Exception;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;
use Voice\Stomp\Queue\Jobs\StompJob;
use Voice\Stomp\Queue\Stomp\ClientWrapper;
use Voice\Stomp\Queue\Stomp\ConfigWrapper;

class StompQueue extends Queue implements QueueInterface
{
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

    public function __construct(ClientWrapper $stompClient)
    {
        $this->readQueues = ConfigWrapper::get('read_queues');
        $this->writeQueue = ConfigWrapper::get('write_queue');
        $this->client = $stompClient->client;
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
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, []);
    }

    /**
     * @param int $delay
     * @return array
     */
    public function makeDelayHeader(int $delay)
    {
        // TODO: remove ActiveMq hard coding
        return ['AMQ_SCHEDULED_DELAY' => $delay * 1000];
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
        $decoded = json_decode($payload, true);
        $headers = $this->setHeaders($decoded, $options);

        Arr::forget($decoded, '_headers');
        $message = new Message(json_encode($decoded), $headers);

        $queue = $this->getWriteQueue();

        Log::info('[STOMP] Pushing stomp payload to queue: ' . print_r(['payload' => $message, 'queue' => $queue], true));

        return $this->client->send($queue, $message);
    }

    protected function setHeaders(array $payload, array $options): array
    {
        if (!array_key_exists('_headers', $payload)) {
            return $options;
        }

        // If left in the header, it will screw up the whole event redelivery.
        // Also TODO: remove ActiveMq hard coding
        Arr::forget($payload['_headers'], ['_AMQ_SCHED_DELIVERY', 'content-length']);

        $headers = array_merge($options, $payload['_headers']);

        return $headers;
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
            $job = $this->client->read();
        } catch (Exception $e) {
            Log::error("[STOMP] Stomp failed to read any data from '$queue' queue. " . $e->getMessage());

            return null;
        }

        if (is_null($job) || !($job instanceof Frame)) {
            return null;
        }

        Log::info('[STOMP] Popping a job from queue: ' . print_r($job, true));

        return new StompJob($this->container, $this, $job, $this->getQueue($job));
    }

    /**
     * Create a payload array from the given job and data.
     *
     * @param object|string $job
     * @param string $queue
     * @param string $data
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'id' => $this->getRandomId(),
            // 'raw' => $job,
        ]);
    }

    /**
     * Get a random ID string.
     *
     * @return string
     */
    protected function getRandomId(): string
    {
        return Str::uuid();
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

    /**
     * Get the queue or return the default.
     *
     * @param string|null $queue
     * @return string
     */
    public function getReadQueues(?string $queue)
    {
        return $queue ?: $this->readQueues;
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

    protected function getWriteQueue()
    {
        $queues = $this->parseDelimitedQueues($this->writeQueue);

        return $queues[0];
    }

    public function getQueue(Frame $frame)
    {
        return $this->client->getSubscriptions()->getSubscription($frame)->getDestination();
    }
}
