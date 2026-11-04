<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Asseco\Stomp\Queue\Contracts\RetriesOnStomp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Opted-in queued listener; throws on every run. */
class FailingRetryListener implements ShouldQueue, RetriesOnStomp
{
    use InteractsWithQueue;

    public $connection = 'stomp';

    public $tries = 2;

    public $backoff = 5;

    public function handle(SomethingHappened $event): void
    {
        Recorder::$attempts[] = $this->attempts();

        throw new RuntimeException('listener boom');
    }

    public function failed(SomethingHappened $event, Throwable $e): void
    {
        Recorder::$failed[] = $e;
    }
}
