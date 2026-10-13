<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\Jobs\StompJob;
use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\Stomp\ConnectionWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\Support\FakeBroker;
use Asseco\Stomp\Tests\TestCase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Stomp\Exception\ConnectionException;
use Throwable;

/**
 * A broker outage longer than the reconnect budget (live30test, 2026-10-12: broker down
 * ~16 min, workers gave up after ~2 min and never consumed again). Runs the real driver
 * and stomp-php client against a fake broker that is killed and brought back.
 */
class StompReconnectGiveUpTest extends TestCase
{
    private ?FakeBroker $broker = null;

    public function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs ext-pcntl and ext-posix for the fake broker');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->broker?->kill();
        parent::tearDown();
    }

    private function connectQueue(): StompQueue
    {
        $this->broker = FakeBroker::start();

        config([
            'queue.connections.stomp.host' => '127.0.0.1',
            'queue.connections.stomp.port' => $this->broker->port,
            'queue.connections.stomp.username' => null,
            'queue.connections.stomp.password' => null,
            'queue.connections.stomp.reconnect_tries' => 2,
            'queue.connections.stomp.reconnect_backoff_base_ms' => 1,
            'queue.connections.stomp.reconnect_backoff_max_ms' => 1,
            'queue.connections.stomp.read_queues' => 'eloquent::live30',
            'queue.connections.stomp.consumer_ack_mode' => 'auto',
        ]);

        $queue = new StompQueue(new ClientWrapper(new ConnectionWrapper()));
        $queue->setContainer($this->app);

        return $queue;
    }

    private function stopBroker(): void
    {
        $this->broker->kill();
        $this->broker = null;
    }

    /** Poll like `queue:work` does until the driver gives up on the dead broker. */
    private function popUntilGiveUp(StompQueue $queue): Throwable
    {
        for ($i = 0; $i < 50; $i++) {
            try {
                $queue->pop();
            } catch (Throwable $e) {
                return $e;
            }
        }

        $this->fail('pop() kept returning against a dead broker instead of giving up');
    }

    /** Poll like `queue:work` does (reads are non-blocking) until a message arrives. */
    private function popUntilJob(StompQueue $queue): ?StompJob
    {
        for ($i = 0; $i < 50; $i++) {
            if ($job = $queue->pop()) {
                return $job;
            }
            usleep(20_000);
        }

        return null;
    }

    public function test_give_up_tells_the_queue_worker_to_quit_so_the_supervisor_restarts_it(): void
    {
        // Laravel's Worker catches and reports whatever pop() throws, and exits on its own only
        // for DB-style "lost connection" messages, so throwing alone left the worker running.
        $worker = $this->app->make('queue.worker');
        $queue = $this->connectQueue();
        $this->stopBroker();

        $this->assertInstanceOf(ConnectionException::class, $this->popUntilGiveUp($queue));
        $this->assertTrue($worker->shouldQuit);
    }

    public function test_the_real_queue_worker_loop_returns_after_giving_up(): void
    {
        $this->app->instance(StompQueue::class, $this->connectQueue());
        $this->stopBroker();

        $worker = $this->app->make('queue.worker');

        // watchdog: if the loop keeps going, stop it ourselves and fail
        $iterations = 0;
        $hung = false;
        $this->app['events']->listen(Looping::class, function () use ($worker, &$iterations, &$hung) {
            if (++$iterations > 20) {
                $hung = true;
                $worker->shouldQuit = true;
            }
        });

        $status = $worker->daemon('stomp', 'eloquent::live30', new WorkerOptions(sleep: 0));

        $this->assertFalse($hung, 'queue:work kept running against a dead broker after giving up');
        $this->assertSame(Worker::EXIT_SUCCESS, $status);
    }

    public function test_give_up_outside_a_queue_worker_does_not_create_one(): void
    {
        $queue = $this->connectQueue();
        $this->stopBroker();

        $this->popUntilGiveUp($queue);

        $this->assertFalse($this->app->resolved('queue.worker'));
    }

    public function test_a_worker_that_gave_up_consumes_again_once_the_broker_is_back(): void
    {
        $queue = $this->connectQueue();
        $port = $this->broker->port;
        $this->stopBroker();
        $this->popUntilGiveUp($queue);

        $this->broker = FakeBroker::start($port, 'after-outage');

        // The failed connect used to leave the client half-reset (isConnecting=true,
        // protocol=null), so every later pop died with "Call to a member function
        // getSubscribeFrame() on null" — an Error that read() never reconnected from.
        $job = $this->popUntilJob($queue);

        $this->assertInstanceOf(StompJob::class, $job);
        $this->assertSame('fake-1', $job->getJobId(), 'the message the restarted broker delivered');
    }
}
