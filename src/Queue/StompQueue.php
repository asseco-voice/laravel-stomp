<?php

namespace Norgul\Stomp\Queue;

use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Norgul\Stomp\Queue\Jobs\StompJob;
use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\Network\Connection;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;

class StompQueue extends Queue implements QueueInterface
{
    /**
     * Queue name
     */
    public string $queue;

    /**
     * Stomp instance from stomp-php repo
     */
    public StatefulStomp $stompClient;

    /**
     * Current job being processed.
     *
     * @var StompJob
     */
    protected StompJob $currentJob;

    protected Connection $connection;

    /**
     * StompQueue constructor.
     * @param StatefulStomp $stompClient
     * @param $queue
     * @param $options
     * @throws ConnectionException
     */
    public function __construct()
    {
        $this->queue = Config::get('queue.connections.stomp.queue');
        $this->connection = $this->initConnection();
        $client = new Client($this->connection);
        $this->setCredentials($client);
        $client->setSync(false); // U config?
        $this->stompClient = new StatefulStomp($client);
        Log::info('[STOMP] Queue initialized successfully.');
    }

    /**
     * @return Connection
     * @throws \Stomp\Exception\ConnectionException
     */
    protected function initConnection(): Connection
    {
        $protocol = Config::get('queue.connections.stomp.protocol');
        $host = Config::get('queue.connections.stomp.host');
        $port = Config::get('queue.connections.stomp.port');

        return new Connection("$protocol://$host:$port");
    }

    /**
     * @param Client $client
     */
    protected function setCredentials(Client $client): void
    {
        $username = Config::get('queue.connections.stomp.username');
        $password = Config::get('queue.connections.stomp.password');

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
        return 1;
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

        Log::info('[STOMP] Pushing a payload to queue: ' . print_r(['payload' => $message, 'queue' => $queue], true));

        return $this->stompClient->send($queue, $message);
    }

    /**
     * Push a raw payload onto the queue after encrypting the payload.
     *
     * @param  string  $payload
     * @param  string  $queue
     * @param  int     $delay
     * @return mixed
     */
    public function recreate($payload, $queue, $delay)
    {
        return $this->pushRaw($payload, $queue, $delay);
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
        $this->stompClient->subscribe($this->getQueue($queue));
        $job = $this->stompClient->read();

        if (!is_null($job) && ($job instanceof Frame)) {
            return new StompJob($this->container, $this, $job);
        }

        return null;
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
        ]);
    }

    /**
     * Close the connection.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->currentJob && !$this->currentJob->isDeletedOrReleased()) {
            $this->connection->disconnect();
        }
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

    /**
     * Delete a message from the Stomp queue.
     *
     * @param string $queue
     * @param string|Frame $message
     * @return void
     */
    public function deleteMessage($queue, Frame $message)
    {
        $this->stompClient->ack($message);
    }

}
