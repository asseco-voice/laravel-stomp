<?php

namespace Norgul\Stomp\Queue\Connectors;

use Illuminate\Queue\Connectors\ConnectorInterface;
use Norgul\Stomp\Queue\StompQueue;

class StompConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param array $config
     * @return \Illuminate\Contracts\Queue\Queue
     * @throws \Stomp\Exception\ConnectionException
     */
    public function connect(array $config)
    {
        return new StompQueue();
    }
}
