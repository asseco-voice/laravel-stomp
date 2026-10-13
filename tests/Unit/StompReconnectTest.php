<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\TestCase;
use Mockery;
use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\Network\Connection;
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
    private function makeQueue(StatefulStomp $stateful, array $stompConfig = []): StompQueue
    {
        config(array_merge([
            'queue.connections.stomp.reconnect_tries' => 3,
            'queue.connections.stomp.reconnect_backoff_base_ms' => 1,
            'queue.connections.stomp.reconnect_backoff_max_ms' => 1,
            'queue.connections.stomp.read_queues' => 'eloquent',
            'queue.connections.stomp.write_queues' => 'eloquent',
            'queue.connections.stomp.consumer_ack_mode' => 'auto',
        ], array_combine(
            array_map(fn ($key) => "queue.connections.stomp.$key", array_keys($stompConfig)),
            $stompConfig
        )));

        $wrapper = Mockery::mock(ClientWrapper::class);
        $wrapper->client = $stateful;

        return new class($wrapper) extends StompQueue {
            public function publicReconnect(bool $subscribe = true): void
            {
                $this->reconnect($subscribe);
            }

            public function publicRead()
            {
                return $this->read('eloquent');
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

    public function test_a_failed_attempt_does_not_leave_queues_marked_as_subscribed(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess')->zeroOrMoreTimes();
        $client->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();
        $client->shouldReceive('disconnect')->andReturnNull()->zeroOrMoreTimes();
        $client->shouldReceive('connect')->andReturn(true)->zeroOrMoreTimes();

        // attempt 1 subscribes `first`, then the connection drops while subscribing `second`
        $subscribed = [];
        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);
        $stateful->shouldReceive('subscribe')->andReturnUsing(function ($queue) use (&$subscribed) {
            $subscribed[] = $queue;
            if (count($subscribed) === 2) {
                throw new ConnectionException('dropped mid-subscribe');
            }
        });

        $queue = $this->makeQueue($stateful, ['read_queues' => 'first::live30;second::live30']);
        $queue->publicReconnect();

        // attempt 2 is a new session, so it must subscribe BOTH queues again
        $this->assertCount(4, $subscribed);
        $this->assertSame(array_slice($subscribed, 0, 2), array_slice($subscribed, 2));
    }

    public function test_publish_give_up_does_not_stop_the_queue_worker(): void
    {
        // a job on another connection (redis, database) may publish to STOMP during an outage;
        // that worker has nothing to reconnect and must keep running
        $worker = $this->app->make('queue.worker');

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess')->zeroOrMoreTimes();
        $client->shouldReceive('isConnected')->andReturn(false)->zeroOrMoreTimes();
        $client->shouldReceive('disconnect')->andReturnNull()->zeroOrMoreTimes();
        $client->shouldReceive('connect')->andThrow(new ConnectionException('broker down'));

        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);

        $queue = $this->makeQueue($stateful);

        try {
            $queue->publicReconnect(false);
            $this->fail('publish-path reconnect should still give up with a ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertFalse($worker->shouldQuit);
        }
    }

    public function test_read_reconnects_after_an_error_from_a_half_reset_client(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('sendAlive')->zeroOrMoreTimes();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess')->zeroOrMoreTimes();
        $client->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();
        $client->shouldReceive('getConnection')->andReturn($connection)->zeroOrMoreTimes();
        $client->shouldReceive('disconnect')->andReturnNull()->zeroOrMoreTimes();
        $client->shouldReceive('connect')->once()->andReturn(true);

        // live30test: a half-reset stomp-php client fails with an Error, not an Exception
        $calls = 0;
        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);
        $stateful->shouldReceive('subscribe')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \Error('Call to a member function getSubscribeFrame() on null');
            }
        });
        $stateful->shouldReceive('read')->andReturn(false);

        $queue = $this->makeQueue($stateful);

        $this->assertFalse($queue->publicRead());
    }
}
