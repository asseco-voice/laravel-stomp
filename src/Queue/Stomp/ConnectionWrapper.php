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

        // Default 0 preserves the historical non-blocking poll behaviour; configurable so
        // operators can let stomp-php service heartbeats during idle reads.
        $this->connection->setReadTimeout((int) (Config::get('read_timeout') ?? 0));
    }
}
