<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\TestCase;
use Mockery;
use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\StatefulStomp;

/**
 * Behavioural tests for the reconnect resilience rewrite. The connect() path is
 * driven through a mocked stomp-php client so no real broker is needed.
 */
class StompReconnectTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Build a StompQueue over a mocked client, with fast (jitter-bounded) backoff. */
    private function makeQueue(StatefulStomp $stateful): StompQueue
    {
        config([
            'queue.connections.stomp.reconnect_tries' => 3,
            'queue.connections.stomp.reconnect_backoff_base_ms' => 1,
            'queue.connections.stomp.reconnect_backoff_max_ms' => 1,
            'queue.connections.stomp.read_queues' => 'eloquent',
            'queue.connections.stomp.write_queues' => 'eloquent',
            'queue.connections.stomp.consumer_ack_mode' => 'auto',
        ]);

        $wrapper = Mockery::mock(ClientWrapper::class);
        $wrapper->client = $stateful;

        return new class($wrapper) extends StompQueue {
            public function publicReconnect(bool $subscribe = true): void
            {
                $this->reconnect($subscribe);
            }
        };
    }

    public function test_reconnect_throws_after_exhausting_tries(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess')->zeroOrMoreTimes();
        $client->shouldReceive('isConnected')->andReturn(false)->zeroOrMoreTimes();
        $client->shouldReceive('disconnect')->andReturnNull()->zeroOrMoreTimes();
        // the headline assertion: exactly `reconnect_tries` (3) connect attempts, all failing
        $client->shouldReceive('connect')->times(3)->andThrow(new ConnectionException('broker down'));

        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);

        $queue = $this->makeQueue($stateful);

        $this->expectException(ConnectionException::class);
        $queue->publicReconnect();
    }

    public function test_reconnect_recovers_without_throwing_when_a_later_attempt_succeeds(): void
    {
        $attempts = 0;

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess')->zeroOrMoreTimes();
        $client->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();
        $client->shouldReceive('disconnect')->andReturnNull()->zeroOrMoreTimes();
        // first attempt fails, second succeeds → no exception, no give-up
        $client->shouldReceive('connect')->times(2)->andReturnUsing(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('transient blip');
            }

            return true;
        });

        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);
        // resubscribe path
        $stateful->shouldReceive('getSubscriptions')->andReturn(Mockery::mock(['getSubscription' => null]))->zeroOrMoreTimes();
        $stateful->shouldReceive('subscribe')->andReturnNull()->zeroOrMoreTimes();

        $queue = $this->makeQueue($stateful);

        $queue->publicReconnect();

        $this->assertSame(2, $attempts, 'should stop retrying as soon as a connect succeeds');
    }
}
