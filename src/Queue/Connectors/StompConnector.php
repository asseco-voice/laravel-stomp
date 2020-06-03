<?php

namespace Voice\Stomp\Queue\Connectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Config;
use Voice\Stomp\Horizon\Listeners\StompFailedEvent;
use Voice\Stomp\Horizon\StompQueue as HorizonStompQueue;
use Voice\Stomp\Queue\StompQueue;
use Stomp\Network\Connection;

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
     * @param array $config
     * @return \Illuminate\Contracts\Queue\Queue
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
     * @param string $worker
     * @param Connection $connection
     * @param string $queue
     * @param array $options
     * @return HorizonStompQueue|StompQueue
     * @throws \Stomp\Exception\ConnectionException
     */
    public function selectWorker()
    {
        $worker = Config::get('queue.connections.stomp.worker');
        switch ($worker) {
            case 'horizon':
                return new HorizonStompQueue();
            default:
                return new StompQueue();
        }
    }
}
