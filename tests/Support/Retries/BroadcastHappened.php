<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** A broadcast event with no channels: BroadcastEvent::handle() asks for them and stops there. */
class BroadcastHappened implements ShouldBroadcast
{
    public $connection = 'stomp';

    public function broadcastOn(): array
    {
        Recorder::$ran[] = 'broadcast';

        return [];
    }
}
