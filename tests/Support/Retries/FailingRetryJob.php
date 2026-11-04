<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Asseco\Stomp\Queue\Contracts\RetriesOnStomp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Opted in; throws on every run. */
class FailingRetryJob implements ShouldQueue, RetriesOnStomp
{
    use InteractsWithQueue, Queueable;

    public function __construct(public $tries = 3, public $backoff = null, public $maxExceptions = null)
    {
    }

    public function handle(): void
    {
        Recorder::$attempts[] = $this->attempts();

        throw new RuntimeException('boom on attempt ' . $this->attempts());
    }

    public function failed(Throwable $e): void
    {
        Recorder::$failed[] = $e;
    }
}
