<?php

namespace Voice\Stomp\Queue\Stomp;

use Illuminate\Support\Facades\Log;
use Stomp\Client;
use Stomp\Exception\StompException;
use Stomp\StatefulStomp;

class ClientWrapper
{
    public StatefulStomp $client;

    public function __construct(ConnectionWrapper $connectionWrapper)
    {
        $client = new Client($connectionWrapper->connection);
        $client->setSync(false);

        $this->setCredentials($client);

        try {
            $client->connect();
        } catch (StompException $e) {
            Log::error('[STOMP] Connection failed: ' . print_r($e->getMessage(), true));
        }

        $this->client = new StatefulStomp($client);
    }

    protected function setCredentials(Client $client): void
    {
        $username = ConfigWrapper::get('username');
        $password = ConfigWrapper::get('password');

        if ($username && $password) {
            $client->setLogin($username, $password);
        }
    }
}
