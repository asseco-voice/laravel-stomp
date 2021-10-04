<?php

namespace Asseco\Stomp\Queue\Stomp;

use Stomp\Client;
use Stomp\StatefulStomp;

class ClientWrapper
{
    public StatefulStomp $client;

    /**
     * ClientWrapper constructor.
     * @param ConnectionWrapper $connectionWrapper
     * @throws \Stomp\Exception\StompException
     */
    public function __construct(ConnectionWrapper $connectionWrapper)
    {
        $client = new Client($connectionWrapper->connection);

        $client->setSync(false);

        $this->setCredentials($client);

        $client->connect();

        $this->client = new StatefulStomp($client);
    }

    protected function setCredentials(Client $client): void
    {
        $username = Config::get('username');
        $password = Config::get('password');

        if ($username && $password) {
            $client->setLogin($username, $password);
        }
    }
}
