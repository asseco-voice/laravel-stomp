<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A job whose displayName() is not a class name. */
class CustomNameJob implements ShouldQueue
{
    use Queueable;

    public function displayName(): string
    {
        return 'reports: nightly export';
    }

    public function handle(): void
    {
        Recorder::$ran[] = 'custom-name-job';
    }
}
