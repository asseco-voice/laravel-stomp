<?php

namespace Voice\Stomp\Queue\Connectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\App;
use Voice\Stomp\Horizon\Listeners\StompFailedEvent;
use Voice\Stomp\Horizon\StompQueue as HorizonStompQueue;
use Voice\Stomp\Queue\Stomp\ConfigWrapper;
use Voice\Stomp\Queue\StompQueue;

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
     */
    public function selectWorker()
    {
        switch (ConfigWrapper::get('worker')) {
            case 'horizon':
                return App::make(HorizonStompQueue::class);
            default:
                return App::make(StompQueue::class);
        }
    }
}
