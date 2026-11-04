<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Feature;

use Asseco\Stomp\Tests\Support\Retries\BroadcastHappened;
use Asseco\Stomp\Tests\Support\Retries\CustomNameJob;
use Asseco\Stomp\Tests\Support\Retries\Recorder;
use Asseco\Stomp\Tests\Support\StompWorkerTestCase;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Stomp\Transport\Message;

/**
 * Whether a native Laravel job is run is decided by the class of its command (data.commandName), the way
 * Laravel decides it, not by its displayName(), which a job may set to anything.
 */
class NativeJobDetectionTest extends StompWorkerTestCase
{
    private LoggerInterface|MockInterface $log;

    public function setUp(): void
    {
        parent::setUp();

        // StompJob takes the logger when the worker pops it
        $this->log = Mockery::spy(LoggerInterface::class);
        $this->app->instance('stompLog', $this->log);
    }

    /** Take the published message, let $change edit its body, and deliver it to the worker. */
    private function deliverChanged(callable $change): void
    {
        $sent = $this->takeSent();
        $body = $change(json_decode($sent['message']->getBody(), true));

        $this->deliver([
            'queue' => $sent['queue'],
            'message' => new Message(json_encode($body), $sent['message']->getHeaders()),
        ]);
    }

    private function assertSkippedAsMissingClass(): void
    {
        $this->log->shouldHaveReceived('error')->with(Mockery::pattern('/Laravel job class does not exist/'));
    }

    public function test_a_queued_artisan_command_runs(): void
    {
        Artisan::command('stomp-test:record {what}', function (string $what) {
            Recorder::$ran[] = $what;
        });

        Artisan::queue('stomp-test:record', ['what' => 'queued-command'])->onConnection('stomp');
        $this->deliver($this->takeSent());

        $this->work();

        $this->assertSame(['queued-command'], Recorder::$ran);
    }

    public function test_a_job_with_a_custom_display_name_runs(): void
    {
        $this->dispatchToWorker(new CustomNameJob());

        $this->work();

        $this->assertSame(['custom-name-job'], Recorder::$ran);
    }

    public function test_a_broadcast_event_runs(): void
    {
        event(new BroadcastHappened());
        $this->deliver($this->takeSent());

        $this->work();

        $this->assertSame(['broadcast'], Recorder::$ran);
    }

    public function test_a_job_whose_class_does_not_exist_here_is_skipped(): void
    {
        app('queue')->connection('stomp')->push(new CustomNameJob());
        $this->deliverChanged(function (array $body) {
            $body['data']['commandName'] = 'App\\Jobs\\OnlyInAnotherService';
            $body['data']['command'] = str_replace(
                'O:' . strlen(CustomNameJob::class) . ':"' . CustomNameJob::class . '"',
                'O:' . strlen('App\\Jobs\\OnlyInAnotherService') . ':"App\\Jobs\\OnlyInAnotherService"',
                $body['data']['command']
            );

            return $body;
        });

        $this->work();

        $this->assertSame([], Recorder::$ran);
        $this->assertSkippedAsMissingClass();
    }

    public function test_a_broadcast_of_an_event_that_does_not_exist_here_is_skipped(): void
    {
        event(new BroadcastHappened());
        $this->deliverChanged(function (array $body) {
            $this->assertSame(BroadcastEvent::class, $body['data']['commandName']);
            $body['displayName'] = 'App\\Events\\OnlyInAnotherService';

            return $body;
        });

        $this->work();

        $this->assertSame([], Recorder::$ran);
        $this->assertSkippedAsMissingClass();
    }
}
