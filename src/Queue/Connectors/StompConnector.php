<?php

namespace Asseco\Stomp\Queue\Connectors;

use Asseco\Stomp\Horizon\Listeners\StompFailedEvent;
use Asseco\Stomp\Horizon\StompQueue as HorizonStompQueue;
use Asseco\Stomp\Queue\Stomp\Config;
use Asseco\Stomp\Queue\StompQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\JobFailed;
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
        $queue = $this->selectWorker();

        if ($queue instanceof HorizonStompQueue) {
            $this->dispatcher->listen(JobFailed::class, StompFailedEvent::class);
        }

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->close();
        });

        return $queue;
    }

    /**
     * Select worker depending on config.
     */
    public function selectWorker()
    {
        switch (Config::get('worker')) {
            case 'horizon':
                return app(HorizonStompQueue::class);
            default:
                return app(StompQueue::class);
        }
    }
}
