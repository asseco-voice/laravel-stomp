<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\Jobs\StompJob;
use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\Stomp\ConnectionWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\Support\FakeBroker;
use Asseco\Stomp\Tests\TestCase;
use Stomp\Exception\ErrorFrameException;
use Stomp\Transport\Message;

/**
 * StompQueue::sendWithReceipt() with the real stomp-php client against a fake broker: a broker refusal surfaces
 * as an exception, and a MESSAGE that arrives while the client waits for the receipt is still consumed.
 */
class StompSendWithReceiptTest extends TestCase
{
    private const QUEUE = 'eloquent::live30';

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

    /** A queue connected to a fake broker, already subscribed (a first pop, which finds nothing). */
    private function subscribedQueue(string $onSend): StompQueue
    {
        $this->broker = FakeBroker::start(onSend: $onSend);

        config([
            'queue.connections.stomp.host' => '127.0.0.1',
            'queue.connections.stomp.port' => $this->broker->port,
            'queue.connections.stomp.username' => null,
            'queue.connections.stomp.password' => null,
            'queue.connections.stomp.read_queues' => self::QUEUE,
            'queue.connections.stomp.consumer_ack_mode' => 'auto',
        ]);

        $queue = new StompQueue(new ClientWrapper(new ConnectionWrapper()));
        $queue->setContainer($this->app);

        $this->assertNull($queue->pop());

        return $queue;
    }

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

    public function test_a_message_that_arrives_while_waiting_for_the_receipt_is_still_consumed(): void
    {
        $queue = $this->subscribedQueue(FakeBroker::ONSEND_INTERLEAVE);

        $queue->sendWithReceipt(self::QUEUE, new Message('{"retry":true}'));

        $job = $this->popUntilJob($queue);
        $this->assertInstanceOf(StompJob::class, $job, 'the interleaved MESSAGE was not lost');
        $this->assertSame('fake-2', $job->getJobId());
    }

    public function test_an_error_frame_in_answer_to_the_send_throws(): void
    {
        $queue = $this->subscribedQueue(FakeBroker::ONSEND_ERROR);

        $this->expectException(ErrorFrameException::class);
        $this->expectExceptionMessage('AMQ229032');

        $queue->sendWithReceipt(self::QUEUE, new Message('{"retry":true}'));
    }
}
