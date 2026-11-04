<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support;

use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\Support\Retries\Recorder;
use Asseco\Stomp\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Stomp\Client;
use Stomp\Network\Connection;
use Stomp\StatefulStomp;
use Stomp\States\Meta\Subscription;
use Stomp\States\Meta\SubscriptionList;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;

/**
 * The real driver and the real `queue:work --once` command run against a mocked stomp-php client. Every frame the driver sends is captured; a captured message is delivered back to
 * the worker as the next MESSAGE frame, the way a broker would hand it out after its scheduled delay.
 */
abstract class StompWorkerTestCase extends TestCase
{
    protected const DESTINATION = 'eloquent::eloquent_default_queue';

    /** @var array<int, array{queue: string, message: Message}> */
    protected array $sent = [];

    /** @var Frame[] */
    protected array $inbox = [];

    protected bool $brokerAccepts = true;

    protected bool $brokerDown = false;

    protected int $messageId = 0;

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'stomp');
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('queue.connections.stomp.read_queues', self::DESTINATION);
        $app['config']->set('queue.connections.stomp.write_queues', 'eloquent');
        $app['config']->set('queue.connections.stomp.consumer_ack_mode', 'auto');
        $app['config']->set('queue.connections.stomp.reconnect_tries', 1);
        $app['config']->set('queue.connections.stomp.reconnect_backoff_base_ms', 1);
        $app['config']->set('queue.connections.stomp.reconnect_backoff_max_ms', 1);
    }

    public function setUp(): void
    {
        parent::setUp();

        Recorder::reset();

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->app->instance(StompQueue::class, $this->makeQueue());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeQueue(): StompQueue
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('sendAlive')->andReturnTrue();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess');
        $client->shouldReceive('getConnection')->andReturn($connection);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('disconnect');
        $client->shouldReceive('connect')->andThrow(new RuntimeException('broker down'));

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('getDestination')->andReturn(self::DESTINATION);
        $subscriptions = Mockery::mock(SubscriptionList::class);
        $subscriptions->shouldReceive('getSubscription')->andReturn($subscription);

        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);
        $stateful->shouldReceive('getSubscriptions')->andReturn($subscriptions);
        $stateful->shouldReceive('subscribe')->andReturn(1);
        $stateful->shouldReceive('read')->andReturnUsing(fn () => array_shift($this->inbox) ?? false);
        $stateful->shouldReceive('send')->andReturnUsing(function (string $queue, Message $message) {
            if ($this->brokerDown) {
                throw new RuntimeException('socket gone');
            }

            $this->sent[] = ['queue' => $queue, 'message' => $message];

            return $this->brokerAccepts;
        });

        $wrapper = Mockery::mock(ClientWrapper::class);
        $wrapper->client = $stateful;

        return new StompQueue($wrapper);
    }

    /** Dispatch a job onto STOMP and hand the published message to the worker as an incoming frame. */
    protected function dispatchToWorker(object $job): void
    {
        app('queue')->connection('stomp')->push($job);

        $this->deliver($this->takeSent());
    }

    /** Take the one message sent since the last take. */
    protected function takeSent(): array
    {
        $this->assertCount(1, $this->sent, 'exactly one message should have been sent');

        return array_shift($this->sent);
    }

    protected function deliver(array $sent): void
    {
        $this->inbox[] = new Frame('MESSAGE', array_merge($sent['message']->getHeaders(), [
            'subscription' => '1',
            'message-id' => 'm-' . ++$this->messageId,
            'destination' => self::DESTINATION,
        ]), $sent['message']->getBody());
    }

    protected function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'stomp', '--once' => true]);
    }

    protected function body(array $sent): array
    {
        return json_decode($sent['message']->getBody(), true);
    }

    protected function delayMs(array $sent): ?int
    {
        return $sent['message']->getHeaders()[StompQueue::AMQ_SCHEDULED_DELAY] ?? null;
    }

    protected function failedJobs(): array
    {
        return DB::table('failed_jobs')->get()->all();
    }
}
