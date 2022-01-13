<?php

namespace Asseco\Stomp\Queue\Stomp;

use Stomp\Network\Connection;

class ConnectionWrapper
{
    public Connection $connection;

    public function __construct()
    {
        $protocol = Config::get('protocol');
        $host = Config::get('host');
        $port = Config::get('port');

        $this->connection = new Connection("$protocol://$host:$port");

        $this->connection->setReadTimeout(0, 0);
    }
}
