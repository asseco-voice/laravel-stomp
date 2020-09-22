<?php

namespace Voice\Stomp\Queue;

use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stomp\Client;
use Stomp\Exception\StompException;
use Stomp\Network\Connection;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;
use Voice\Stomp\Queue\Jobs\StompJob;

class StompQueue extends Queue implements QueueInterface
{
    /**
     * Queue name
     */
    public string $queue;

    /**
     * List of queues already subscribed to. Preventing multiple same subscriptions
     * @var array
     */
    protected array $subscribedTo = [];

    /**
     * Stomp instance from stomp-php repo
     */
    public StatefulStomp $stompClient;

    public function __construct()
    {
        $this->queue = StompConfig::get('queue');
        $client = new Client($this->initConnection());
        $this->setCredentials($client);
        $client->setSync(false);

        try {
            $client->connect();
            $this->stompClient = new StatefulStomp($client);
            Log::info('[STOMP] Connected successfully.');
        } catch (StompException $e) {
            Log::error('[STOMP] Connection failed: ' . print_r($e->getMessage(), true));
        }
    }

    protected function initConnection(): Connection
    {
        $protocol = StompConfig::get('protocol');
        $host = StompConfig::get('host');
        $port = StompConfig::get('port');

        return new Connection("$protocol://$host:$port");
    }

    protected function setCredentials(Client $client): void
    {
        $username = StompConfig::get('username');
        $password = StompConfig::get('password');

        if ($username && $password) {
            $client->setLogin($username, $password);
        }
    }

    /**
     * Get the size of the queue.
     *
     * @param string|null $queue
     * @return int
     */
    public function size($queue = null)
    {
        // TODO: Implement size() method.
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
     * Push a raw payload onto the queue.
     *
     * @param string $payload
     * @param string|null $queue
     * @param array $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $message = new Message($payload, $options);
        $queue = $this->getQueue($queue);

        Log::info('[STOMP] Pushing stomp payload to queue: ' . print_r(['payload' => $message, 'queue' => $queue], true));

        return $this->stompClient->send($queue, $message);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
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
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        try {
            $this->subscribeToQueues();
            $job = $this->stompClient->read();
        } catch (\Exception $e) {
            Log::error("[STOMP] Stomp failed to read any data from '$queue' queue. " . $e->getMessage());
            return null;
        }

        if (is_null($job) || !($job instanceof Frame)) {
            return null;
        }

        Log::info('[STOMP] Popping a job from queue: ' . print_r($job, true));

        return new StompJob($this->container, $this, $job, $this->getQueue($queue));
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
            'id'  => $this->getRandomId(),
            'raw' => $job,
        ]);
    }

    /**
     * Close the connection.
     *
     * @return void
     */
    public function close(): void
    {
        $this->stompClient->getClient()->disconnect();
    }

    /**
     * Get the queue or return the default.
     *
     * @param string|null $queue
     * @return string
     */
    public function getQueue($queue)
    {
        return $queue ?: $this->queue;
    }

    /**
     * Get a random ID string.
     *
     * @return string
     */
    protected function getRandomId(): string
    {
        return Str::random(32);
    }

    protected function subscribeToQueues(): void
    {
        $queues = $this->parseMultiQueue($this->queue);

        foreach ($queues as $queue) {
            $alreadySubscribed = in_array($queue, $this->subscribedTo);

            if ($alreadySubscribed) {
                continue;
            }

            $getQueue = $this->getQueue($queue);
            $this->stompClient->subscribe($getQueue);
            $this->subscribedTo[] = $getQueue;
        }
    }

    protected function parseMultiQueue(string $queue): array
    {
        return explode(';', $queue);
    }

}
