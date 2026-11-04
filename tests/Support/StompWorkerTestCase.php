<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support;

use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\Support\Retries\Recorder;
use Asseco\Stomp\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\Exception\ErrorFrameException;
use Stomp\Exception\MissingReceiptException;
use Stomp\Network\Connection;
use Stomp\StatefulStomp;
use Stomp\States\Meta\Subscription;
use Stomp\States\Meta\SubscriptionList;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;

/**
 * The real driver and the real `queue:work --once` command run against a mocked stomp-php client. Every frame the
 * driver sends is captured; a captured message is delivered back to the worker as the next MESSAGE frame, the way
 * Artemis hands it out: the headers the app sent come back as message properties and win over the frame's own
 * (subscription, message-id, ...), and the client only takes a frame whose subscription id is its current one.
 */
abstract class StompWorkerTestCase extends TestCase
{
    protected const DESTINATION = 'eloquent::eloquent_default_queue';

    /** @var array<int, array{queue: string, message: Message, receipt: bool}> */
    protected array $sent = [];

    /** @var Frame[] */
    protected array $inbox = [];

    /** How the broker answers a send that waits for a receipt: 'receipt', 'error', 'no-receipt' or 'socket'. */
    protected string $receiptOutcome = 'receipt';

    protected int $messageId = 0;

    /** The id of the worker's subscription; another worker, or the same one after a reconnect, has another. */
    protected string $subscriptionId = 'sub-1';

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
        $subscriptions->shouldReceive('getSubscription')->andReturnUsing(
            fn (Frame $frame) => $frame['subscription'] === $this->subscriptionId ? $subscription : false
        );

        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);
        $stateful->shouldReceive('getSubscriptions')->andReturn($subscriptions);
        $stateful->shouldReceive('subscribe')->andReturnUsing(fn () => $this->subscriptionId);
        $stateful->shouldReceive('read')->andReturnUsing(fn () => array_shift($this->inbox) ?? false);
        // a plain (async) send: what push(), later() and the fail_job_requeue path use
        $stateful->shouldReceive('send')->andReturnUsing(function (string $queue, Message $message) {
            $this->sent[] = ['queue' => $queue, 'message' => $message, 'receipt' => false];

            return true;
        });

        // a send that waits for the broker's RECEIPT (stomp-php sync send)
        $client->shouldReceive('send')->andReturnUsing(
            function (string $queue, Message $message, array $header = [], $sync = null) {
                $this->sent[] = ['queue' => $queue, 'message' => $message, 'receipt' => $sync === true];

                return match ($this->receiptOutcome) {
                    'receipt' => true,
                    'error' => throw new ErrorFrameException(
                        new Frame('ERROR', ['message' => 'AMQ229032: User does not have permission SEND'])
                    ),
                    'no-receipt' => throw new MissingReceiptException('r-1'),
                    'socket' => throw new ConnectionException('Was not possible to write frame!'),
                };
            }
        );

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

    /**
     * Deliver a sent message like Artemis does: it sets the frame headers, then copies every header the app sent
     * (stored as message properties) over them. Only `destination` is not copied back.
     */
    protected function deliver(array $sent): void
    {
        $headers = array_merge([
            'subscription' => $this->subscriptionId,
            'message-id' => 'm-' . ++$this->messageId,
            'destination' => self::DESTINATION,
        ], Arr::except($sent['message']->getHeaders(), ['destination']));

        $this->inbox[] = new Frame('MESSAGE', $headers, $sent['message']->getBody());
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
