<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Asseco\Stomp\Queue\Contracts\RetriesOnStomp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Opted in; releases itself (like WithoutOverlapping does), twice to prove a single re-send. */
class SelfReleasingJob implements ShouldQueue, RetriesOnStomp
{
    use InteractsWithQueue, Queueable;

    public $tries = 3;

    public function handle(): void
    {
        Recorder::$attempts[] = $this->attempts();

        $this->release(30);
        $this->release(30);
    }

    public function failed(Throwable $e): void
    {
        Recorder::$failed[] = $e;
    }
}
