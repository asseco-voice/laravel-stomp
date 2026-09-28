<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\TestCase;
use DateInterval;
use Illuminate\Support\Carbon;
use Mockery;
use Stomp\Client;
use Stomp\StatefulStomp;
use Stomp\Transport\Message;

/**
 * later() must hand the delay to the broker (Artemis scheduled delivery) instead of
 * silently pushing the job for immediate consumption.
 */
class StompLaterDelayTest extends TestCase
{
    /** @var Message[] */
    private array $sent = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function makeQueue(): StompQueue
    {
        config([
            'queue.connections.stomp.read_queues' => 'eloquent',
            'queue.connections.stomp.write_queues' => 'email::live30',
            'queue.connections.stomp.consumer_ack_mode' => 'auto',
        ]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getSessionId')->andReturn('sess');

        $stateful = Mockery::mock(StatefulStomp::class);
        $stateful->shouldReceive('getClient')->andReturn($client);
        $stateful->shouldReceive('send')->andReturnUsing(function ($queue, Message $message) {
            $this->sent[] = $message;

            return true;
        });

        $wrapper = Mockery::mock(ClientWrapper::class);
        $wrapper->client = $stateful;

        return new StompQueue($wrapper);
    }

    private function sentHeaders(): array
    {
        $this->assertCount(1, $this->sent, 'exactly one frame should be sent');

        return $this->sent[0]->getHeaders();
    }

    public function test_later_with_integer_seconds_sets_scheduled_delay_in_ms(): void
    {
        $this->makeQueue()->later(30, 'SomeJob', ['a' => 1]);

        $this->assertSame(30000, $this->sentHeaders()[StompQueue::AMQ_SCHEDULED_DELAY] ?? null);
    }

    public function test_later_with_datetime_sets_delay_until_that_moment(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');

        $this->makeQueue()->later(Carbon::parse('2026-09-28 10:15:00'), 'SomeJob');

        $this->assertSame(900000, $this->sentHeaders()[StompQueue::AMQ_SCHEDULED_DELAY] ?? null);
    }

    public function test_later_with_date_interval_sets_delay(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');

        $this->makeQueue()->later(new DateInterval('PT2M'), 'SomeJob');

        $this->assertSame(120000, $this->sentHeaders()[StompQueue::AMQ_SCHEDULED_DELAY] ?? null);
    }

    public function test_later_with_non_positive_delay_behaves_like_push(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');

        $queue = $this->makeQueue();
        $queue->later(0, 'SomeJob');
        $queue->later(Carbon::parse('2026-09-28 09:00:00'), 'SomeJob');

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as $message) {
            $this->assertArrayNotHasKey(StompQueue::AMQ_SCHEDULED_DELAY, $message->getHeaders());
        }
    }

    public function test_push_sets_no_scheduled_delay(): void
    {
        $this->makeQueue()->push('SomeJob');

        $this->assertArrayNotHasKey(StompQueue::AMQ_SCHEDULED_DELAY, $this->sentHeaders());
    }

    public function test_later_keeps_the_job_payload(): void
    {
        $this->makeQueue()->later(5, 'SomeJob', ['a' => 1]);

        $body = json_decode($this->sent[0]->getBody(), true);
        $this->assertSame('SomeJob', $body['job'] ?? null);
        $this->assertSame(['a' => 1], $body['data'] ?? null);
    }
}
