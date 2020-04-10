<?php

namespace Norgul\Stomp\Queue;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Config;
use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\Network\Connection;
use Stomp\StatefulStomp;
use Stomp\Transport\Message;

class StompQueue implements Queue
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
        $this->stompPHP = new StatefulStomp(new Client($connection));

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
        // TODO: Implement push() method.
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string $queue
     * @param string|object $job
     * @param mixed $data
     * @return mixed
     */
    public function pushOn($queue, $job, $data = '')
    {
        // TODO: Implement pushOn() method.
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
        return $this->stompPHP->send($queue ?: $this->queue, $message);
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
        // TODO: Implement later() method.
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param string $queue
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string|object $job
     * @param mixed $data
     * @return mixed
     */
    public function laterOn($queue, $delay, $job, $data = '')
    {
        // TODO: Implement laterOn() method.
    }

    /**
     * Push an array of jobs onto the queue.
     *
     * @param array $jobs
     * @param mixed $data
     * @param string|null $queue
     * @return mixed
     */
    public function bulk($jobs, $data = '', $queue = null)
    {
        // TODO: Implement bulk() method.
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
     * Get the connection name for the queue.
     *
     * @return string
     */
    public function getConnectionName()
    {
        // TODO: Implement getConnectionName() method.
    }

    /**
     * Set the connection name for the queue.
     *
     * @param string $name
     * @return $this
     */
    public function setConnectionName($name)
    {
        // TODO: Implement setConnectionName() method.
    }
}
