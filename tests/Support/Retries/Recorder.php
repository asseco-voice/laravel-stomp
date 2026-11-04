<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Throwable;

/** What the test jobs saw: the attempt number of each run and the exceptions handed to failed(). */
class Recorder
{
    /** @var int[] */
    public static array $attempts = [];

    /** @var Throwable[] */
    public static array $failed = [];

    public static function reset(): void
    {
        self::$attempts = [];
        self::$failed = [];
    }
}
