<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Asseco\Stomp\Tests\TestCase;
use Mockery;
use Stomp\Client;
use Stomp\StatefulStomp;
use Stomp\Transport\Message;

/**
 * A message sent again (queue:retry from failed_jobs, a re-queued job) must not carry the headers of the MESSAGE
 * frame it was received in. Artemis stores every SEND header as a message property and copies it back over the
 * frame's own on delivery, so a stale `subscription` makes every other consumer drop the frame.
 */
class StompRedeliveryHeadersTest extends TestCase
{
    private const RECEIVED_FRAME_HEADERS = [
        'subscription' => 'sub-old',
        'message-id' => 'ID:old-1',
        'ack' => 'ack-old',
        'destination' => 'eloquent::eloquent_q',
        'redelivered' => 'false',
        'timestamp' => '1767225600000',
        'receipt' => 'r-1',
        'receipt-id' => 'r-1',
        'ingress-timestamp' => '1767225600000',
        '_AMQ_DUPL_ID' => 'dup',
        'content-length' => '12',
    ];

    /** @var Message[] */
    private array $sent = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeQueue(): StompQueue
    {
        config([
            'queue.connections.stomp.read_queues' => 'eloquent',
            'queue.connections.stomp.write_queues' => 'eloquent',
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

    public function test_forget_headers_for_redelivery_keeps_only_app_headers(): void
    {
        $headers = $this->makeQueue()->forgetHeadersForRedelivery(self::RECEIVED_FRAME_HEADERS + [
            StompQueue::CORRELATION => 'corr-1',
            'persistent' => 'true',
            'priority' => '4',
            'x-tenant' => 'acme',
        ]);

        $this->assertSame([
            StompQueue::CORRELATION => 'corr-1',
            'persistent' => 'true',
            'priority' => '4',
            'x-tenant' => 'acme',
        ], $headers);
    }

    public function test_a_failed_job_pushed_again_from_its_stored_payload_drops_the_received_frame_headers(): void
    {
        // what queue:retry hands to pushRaw: the failed_jobs payload, headers stored under _headers
        $this->makeQueue()->pushRaw(json_encode([
            'uuid' => 'u-1',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            StompQueue::HEADERS_KEY => self::RECEIVED_FRAME_HEADERS + ['x-tenant' => 'acme'],
        ]));

        $this->assertCount(1, $this->sent);
        $headers = $this->sent[0]->getHeaders();
        foreach (array_keys(self::RECEIVED_FRAME_HEADERS) as $header) {
            $this->assertArrayNotHasKey($header, $headers);
        }
        $this->assertSame('acme', $headers['x-tenant']);
    }
}
