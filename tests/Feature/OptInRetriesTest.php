<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Feature;

use Asseco\Stomp\Queue\Exceptions\RequeueFailedException;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\Support\Retries\ExpiredRetryJob;
use Asseco\Stomp\Tests\Support\Retries\FailingRetryJob;
use Asseco\Stomp\Tests\Support\Retries\FailingRetryListener;
use Asseco\Stomp\Tests\Support\Retries\PlainFailingJob;
use Asseco\Stomp\Tests\Support\Retries\PlainFailingListener;
use Asseco\Stomp\Tests\Support\Retries\Recorder;
use Asseco\Stomp\Tests\Support\Retries\SelfReleasingJob;
use Asseco\Stomp\Tests\Support\Retries\SomethingHappened;
use Asseco\Stomp\Tests\Support\StompWorkerTestCase;
use Illuminate\Queue\MaxAttemptsExceededException;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Stomp\Transport\Message;

/**
 * Opt-in retries (RetriesOnStomp). A message the driver re-sends is delivered back to the worker as the next
 * MESSAGE frame, the way a broker would hand it out after its scheduled delay.
 */
class OptInRetriesTest extends StompWorkerTestCase
{
    private const RECEIVED_FRAME_HEADERS = ['subscription', 'message-id', 'ack', 'destination', 'redelivered',
        'timestamp', 'receipt', 'receipt-id', 'ingress-timestamp'];

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

    public function test_a_re_sent_job_runs_on_a_worker_with_another_subscription(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 2, backoff: 1));
        $this->work();
        $resent = $this->takeSent();

        // another replica on the queue, or this worker after a reconnect or restart
        $this->subscriptionId = 'sub-2';
        $this->deliver($resent);
        $this->work();

        $this->assertSame([1, 2], Recorder::$attempts, 'the retry was consumed, not dropped as a wrong frame');
        $this->assertCount(1, Recorder::$failed);
    }

    public function test_a_re_send_carries_none_of_the_received_frame_headers(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3));
        $this->work();

        $headers = $this->takeSent()['message']->getHeaders();

        foreach (self::RECEIVED_FRAME_HEADERS as $header) {
            $this->assertArrayNotHasKey($header, $headers);
        }
        $this->assertArrayHasKey(StompQueue::CORRELATION, $headers, 'app headers are kept');
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

    public function test_a_re_send_waits_for_the_broker_receipt(): void
    {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3));

        $this->work();

        $this->assertTrue($this->takeSent()['receipt'], 'the re-send is a receipt (sync) send');
    }

    /** @return array<string, array{string, string}> */
    public static function refusedResends(): array
    {
        return [
            'ERROR frame (e.g. no SEND permission)' => ['error', 'AMQ229032'],
            'no receipt in time' => ['no-receipt', 'r-1'],
            'connection failure' => ['socket', 'Was not possible to write frame'],
        ];
    }

    #[DataProvider('refusedResends')]
    public function test_a_re_send_the_broker_does_not_confirm_fails_the_job_instead_of_losing_it(
        string $outcome,
        string $cause
    ): void {
        $this->dispatchToWorker(new FailingRetryJob(tries: 3));
        $this->receiptOutcome = $outcome;

        $this->work();

        $this->assertCount(1, $this->sent, 'one re-send, no retry loop');
        $this->assertCount(1, Recorder::$failed);
        $this->assertInstanceOf(RequeueFailedException::class, Recorder::$failed[0]);
        $this->assertStringContainsString('attempt 2', Recorder::$failed[0]->getMessage());
        $this->assertStringContainsString($cause, Recorder::$failed[0]->getMessage());

        $failedJobs = $this->failedJobs();
        $this->assertCount(1, $failedJobs);
        $this->assertStringContainsString('attempt 2', $failedJobs[0]->exception);
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
        $this->assertArrayNotHasKey('subscription', $resent['message']->getHeaders());
        $this->assertArrayNotHasKey('message-id', $resent['message']->getHeaders());
    }

    public function test_an_external_event_is_never_re_sent(): void
    {
        $handled = 0;
        Event::listen('stomp.eloquent.eloquent_default_queue', function () use (&$handled) {
            $handled++;

            throw new RuntimeException('external boom');
        });

        // not a Laravel job (no CallQueuedHandler), even though its body names an opted-in class
        $this->deliver(['message' => new Message(json_encode([
            'displayName' => FailingRetryJob::class,
            'data' => ['commandName' => FailingRetryJob::class],
            'maxTries' => 3,
        ]), ['type' => 'external'])]);

        $this->work();

        $this->assertSame(1, $handled, 'the external event was handled');
        $this->assertSame([], $this->sent);
    }
}
