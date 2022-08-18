<?php

namespace Asseco\Stomp\Queue\Connectors;

use Asseco\Stomp\Queue\StompQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;

class StompConnector implements ConnectorInterface
{
    /**
     * @var Dispatcher
     */
    private Dispatcher $dispatcher;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     *
     * @throws \Stomp\Exception\ConnectionException
     */
    public function connect(array $config)
    {
        /** @var StompQueue $queue */
        $queue = app(StompQueue::class);

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->disconnect();
        });

        return $queue;
    }
}
