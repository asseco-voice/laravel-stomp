<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

/** Queued listener that does not opt in; throws on every run. */
class PlainFailingListener implements ShouldQueue
{
    public $connection = 'stomp';

    public $tries = 2;

    public function handle(SomethingHappened $event): void
    {
        throw new RuntimeException('listener boom');
    }
}
