<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Unit;

use Asseco\Stomp\Queue\StompQueue;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for the reconnect backoff curve — no broker / Laravel boot needed.
 */
class ReconnectBackoffTest extends TestCase
{
    public function test_backoff_grows_exponentially_from_base(): void
    {
        $this->assertSame(200, StompQueue::reconnectBackoffMs(1, 200, 30000));
        $this->assertSame(400, StompQueue::reconnectBackoffMs(2, 200, 30000));
        $this->assertSame(800, StompQueue::reconnectBackoffMs(3, 200, 30000));
        $this->assertSame(1600, StompQueue::reconnectBackoffMs(4, 200, 30000));
    }

    public function test_backoff_is_capped_at_max(): void
    {
        // 200 * 2^19 is huge; must be clamped to the configured ceiling
        $this->assertSame(30000, StompQueue::reconnectBackoffMs(20, 200, 30000));
    }

    public function test_attempt_below_one_is_clamped_to_first_attempt(): void
    {
        $this->assertSame(200, StompQueue::reconnectBackoffMs(0, 200, 30000));
        $this->assertSame(200, StompQueue::reconnectBackoffMs(-5, 200, 30000));
    }
}
