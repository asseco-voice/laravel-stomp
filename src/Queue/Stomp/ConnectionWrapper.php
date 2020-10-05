<?php

namespace Voice\Stomp\Queue\Stomp;

use Stomp\Network\Connection;

class ConnectionWrapper
{
    public Connection $connection;

    public function __construct()
    {
        $protocol = ConfigWrapper::get('protocol');
        $host = ConfigWrapper::get('host');
        $port = ConfigWrapper::get('port');

        $this->connection = new Connection("$protocol://$host:$port");

        $this->connection->setReadTimeout(30, 0);
    }
}
