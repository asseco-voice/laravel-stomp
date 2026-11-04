<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Not opted in; throws on every run. */
class PlainFailingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $tries = 3;

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
