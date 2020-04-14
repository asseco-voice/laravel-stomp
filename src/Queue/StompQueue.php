<?php

namespace Norgul\Stomp\Queue;

use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Config;
use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\Network\Connection;
use Stomp\StatefulStomp;
use Stomp\Transport\Message;

class StompQueue extends Queue implements QueueInterface
{
    public string $host;
    public string $port;
    public string $username;
    public string $password;

    /**
     * Queue name
     */
    public string $queue;

    /**
     * Stomp instance from stomp-php repo
     */
    public StatefulStomp $stompPHP;

    /**
     * StompQueue constructor.
     * @throws ConnectionException
     */
    public function __construct()
    {
        $this->host = Config::get('queue.connections.stomp.host');
        $this->port = Config::get('queue.connections.stomp.port');
        $this->username = Config::get('queue.connections.stomp.username');
        $this->password = Config::get('queue.connections.stomp.password');
        $this->queue = Config::get('queue.connections.stomp.queue');

        $connection = new Connection($this->host);
        $client = new Client($connection);

        if($this->username && $this->password){
            $client->setLogin($this->username, $this->password);
        }

        $this->stompPHP = new StatefulStomp($client);
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
        return $this->pushRaw($this->createPayload($job, $data), $queue);
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
        // TODO: check...options are headers. Is this correct?
        $message = new Message($payload, $options);
        return $this->stompPHP->send($this->getQueue($queue), $message);
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
        $payload = $this->createPayload($job, $data, $queue);
        return $this->pushRaw($payload, $queue); //, $this->makeDelayHeader($delay));
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        // TODO: Implement pop() method.
    }

    /**
     * Gets queue name from an argument, or default one from config
     * @param $queue
     * @return mixed|string
     */
    protected function getQueue($queue)
    {
        return $queue ?: $this->queue;
    }
}
