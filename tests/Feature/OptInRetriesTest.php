<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Feature;

use Asseco\Stomp\Queue\Exceptions\RequeueFailedException;
use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\Support\Retries\ExpiredRetryJob;
use Asseco\Stomp\Tests\Support\Retries\FailingRetryJob;
use Asseco\Stomp\Tests\Support\Retries\FailingRetryListener;
use Asseco\Stomp\Tests\Support\Retries\PlainFailingJob;
use Asseco\Stomp\Tests\Support\Retries\PlainFailingListener;
use Asseco\Stomp\Tests\Support\Retries\Recorder;
use Asseco\Stomp\Tests\Support\Retries\SelfReleasingJob;
use Asseco\Stomp\Tests\Support\Retries\SomethingHappened;
use Asseco\Stomp\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * Opt-in retries (RetriesOnStomp): the real driver and the real `queue:work --once` command run against a
 * mocked stomp-php client. Every frame the driver sends is captured; a captured message is delivered back to
 * the worker as the next MESSAGE frame, the way a broker would hand it out after its scheduled delay.
 */
class OptInRetriesTest extends TestCase
{
    private const DESTINATION = 'eloquent::eloquent_default_queue';

    /** @var array<int, array{queue: string, message: Message}> */
    private array $sent = [];

    /** @var Frame[] */
    private array $inbox = [];

    private bool $brokerAccepts = true;

    private bool $brokerDown = false;

    private int $messageId = 0;

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

    private function makeQueue(): StompQueue
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
    private function dispatchToWorker(object $job): void
    {
        app('queue')->connection('stomp')->push($job);

        $this->deliver($this->takeSent());
    }

    /** Take the one message sent since the last take. */
    private function takeSent(): array
    {
        $this->assertCount(1, $this->sent, 'exactly one message should have been sent');

        return array_shift($this->sent);
    }

    private function deliver(array $sent): void
    {
        $this->inbox[] = new Frame('MESSAGE', array_merge($sent['message']->getHeaders(), [
            'subscription' => '1',
            'message-id' => 'm-' . ++$this->messageId,
            'destination' => self::DESTINATION,
        ]), $sent['message']->getBody());
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'stomp', '--once' => true]);
    }

    private function body(array $sent): array
    {
        return json_decode($sent['message']->getBody(), true);
    }

    private function delayMs(array $sent): ?int
    {
        return $sent['message']->getHeaders()[StompQueue::AMQ_SCHEDULED_DELAY] ?? null;
    }

    private function failedJobs(): array
    {
        return DB::table('failed_jobs')->get()->all();
    }

    public function test_an_opted_in_job_is_on_attempt_one_on_its_first_run(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3));

        $this->work();

        $this->assertSame([1], Recorder::$attempts);
    }

    public function test_an_opted_in_job_that_throws_is_re_sent_for_its_next_attempt_after_its_backoff(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3, backoff: 15));

        $this->work();

        $resent = $this->takeSent();
        $this->assertSame(self::DESTINATION, $resent['queue'], 'back to the queue it came from');
        $this->assertSame(2, $this->body($resent)['attempts']);
        $this->assertSame(15000, $this->delayMs($resent));
        $this->assertArrayNotHasKey(StompQueue::HEADERS_KEY, $this->body($resent));
        $this->assertSame([], Recorder::$failed);
        $this->assertSame([], $this->failedJobs());
    }

    public function test_a_backoff_array_gives_the_delay_per_attempt(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3, backoff: [10, 60]));

        $this->work();
        $second = $this->takeSent();
        $this->assertSame(10000, $this->delayMs($second));

        $this->deliver($second);
        $this->work();
        $third = $this->takeSent();
        $this->assertSame(3, $this->body($third)['attempts']);
        $this->assertSame(60000, $this->delayMs($third));

        $this->assertSame([1, 2], Recorder::$attempts);
    }

    public function test_an_opted_in_job_that_throws_on_its_last_attempt_fails(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 2, backoff: 1));

        $this->work();
        $this->deliver($this->takeSent());
        $this->work();

        $this->assertSame([], $this->sent, 'no re-send after the last attempt');
        $this->assertSame([1, 2], Recorder::$attempts);
        $this->assertCount(1, Recorder::$failed);
        $this->assertSame('boom on attempt 2', Recorder::$failed[0]->getMessage());

        $failedJobs = $this->failedJobs();
        $this->assertCount(1, $failedJobs);
        $this->assertSame('stomp', $failedJobs[0]->connection);
        $this->assertSame(self::DESTINATION, $failedJobs[0]->queue);
        $this->assertStringContainsString('boom on attempt 2', $failedJobs[0]->exception);
    }

    public function test_an_opted_in_job_without_tries_fails_on_its_first_exception(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: null));

        $this->work();

        $this->assertSame([], $this->sent);
        $this->assertCount(1, Recorder::$failed);
        $this->assertCount(1, $this->failedJobs());
    }

    public function test_an_opted_in_job_past_its_retry_until_fails_without_a_re_send(): void
    {
        $this->dispatchToWorker(new ExpiredRetryJob());

        $this->work();

        $this->assertSame([], $this->sent);
        $this->assertCount(1, Recorder::$failed);
        $this->assertInstanceOf(MaxAttemptsExceededException::class, Recorder::$failed[0]);
        $this->assertCount(1, $this->failedJobs());
    }

    public function test_max_exceptions_fails_the_job_before_its_tries_run_out(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 10, maxExceptions: 2));

        $this->work();
        $this->deliver($this->takeSent());
        $this->work();

        $this->assertSame([], $this->sent);
        $this->assertSame([1, 2], Recorder::$attempts);
        $this->assertCount(1, Recorder::$failed);
        $this->assertCount(1, $this->failedJobs());
    }

    public function test_a_re_send_the_broker_refuses_fails_the_job_instead_of_losing_it(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3));
        $this->brokerAccepts = false;

        $this->work();

        $this->assertCount(1, $this->sent, 'the re-send was attempted');
        $this->assertCount(1, Recorder::$failed);
        $this->assertInstanceOf(RequeueFailedException::class, Recorder::$failed[0]);
        $this->assertStringContainsString('attempt 2', Recorder::$failed[0]->getMessage());

        $failedJobs = $this->failedJobs();
        $this->assertCount(1, $failedJobs);
        $this->assertStringContainsString('attempt 2', $failedJobs[0]->exception);
    }

    public function test_a_re_send_that_throws_fails_the_job_instead_of_losing_it(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3));
        $this->brokerDown = true;

        $this->work();

        $this->assertCount(1, Recorder::$failed);
        $this->assertInstanceOf(RequeueFailedException::class, Recorder::$failed[0]);
        $this->assertCount(1, $this->failedJobs());
    }

    public function test_a_job_that_releases_itself_is_re_sent_once_with_its_delay(): void
    {
        $this->dispatchToWorker(new SelfReleasingJob());

        $this->work();

        $resent = $this->takeSent();
        $this->assertSame(2, $this->body($resent)['attempts']);
        $this->assertSame(30000, $this->delayMs($resent));
        $this->assertSame([], Recorder::$failed);
        $this->assertSame([], $this->failedJobs());

        $this->deliver($resent);
        $this->work();
        $this->assertSame([1, 2], Recorder::$attempts);
    }

    public function test_an_opted_in_queued_listener_is_retried_and_then_fails(): void
    {
        Event::listen(SomethingHappened::class, FailingRetryListener::class);

        event(new SomethingHappened());
        $this->deliver($this->takeSent());

        $this->work();
        $second = $this->takeSent();
        $this->assertSame(2, $this->body($second)['attempts']);
        $this->assertSame(5000, $this->delayMs($second));

        $this->deliver($second);
        $this->work();

        $this->assertSame([], $this->sent);
        $this->assertSame([1, 2], Recorder::$attempts);
        $this->assertCount(1, Recorder::$failed);
        $this->assertSame('listener boom', Recorder::$failed[0]->getMessage());
        $this->assertCount(1, $this->failedJobs());
    }

    public function test_a_job_that_does_not_opt_in_is_dropped_as_before_when_requeue_is_off(): void
    {
        $this->dispatchToWorker(new PlainFailingJob());

        $this->work();

        $this->assertSame([0], Recorder::$attempts, 'attempts stay 0-based');
        $this->assertSame([], $this->sent);
        $this->assertSame([], Recorder::$failed);
        $this->assertSame([], $this->failedJobs());
    }

    public function test_a_listener_that_does_not_opt_in_is_dropped_as_before_when_requeue_is_off(): void
    {
        Event::listen(SomethingHappened::class, PlainFailingListener::class);

        event(new SomethingHappened());
        $this->deliver($this->takeSent());

        $this->work();

        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->failedJobs());
    }

    public function test_a_job_that_does_not_opt_in_keeps_the_requeue_behaviour_when_requeue_is_on(): void
    {
        config(['queue.connections.stomp.fail_job_requeue' => true]);

        $this->dispatchToWorker(new PlainFailingJob());

        $this->work();

        $resent = $this->takeSent();
        $this->assertSame(self::DESTINATION, $resent['queue']);
        $this->assertSame(1, $this->body($resent)['attempts']);
        $this->assertSame(1, $this->body($resent)['backoff'], 'auto_backoff: 1^2');
        $this->assertSame(1000, $this->delayMs($resent));
        $this->assertSame([], Recorder::$failed);
    }

    public function test_an_external_event_is_never_re_sent(): void
    {
        Event::listen('stomp.eloquent.eloquent_default_queue', function () {
            throw new RuntimeException('external boom');
        });

        $this->deliver(['message' => new Message(json_encode(['foo' => 'bar']), ['type' => 'external'])]);

        $this->work();

        $this->assertSame([], $this->sent);
    }
}
