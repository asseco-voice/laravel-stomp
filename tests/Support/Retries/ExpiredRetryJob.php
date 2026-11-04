<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Asseco\Stomp\Queue\Contracts\RetriesOnStomp;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/** Opted in; its retry window has already closed. */
class ExpiredRetryJob implements ShouldQueue, RetriesOnStomp
{
    use InteractsWithQueue, Queueable;

    public $tries = 5;

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->subMinute();
    }

    public function handle(): void
    {
        Recorder::$attempts[] = $this->attempts();

        throw new RuntimeException('boom');
    }

    public function failed(Throwable $e): void
    {
        Recorder::$failed[] = $e;
    }
}
