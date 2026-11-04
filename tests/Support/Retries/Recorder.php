<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

use Throwable;

/** What the test jobs saw: what ran, the attempt number of each run and the exceptions handed to failed(). */
class Recorder
{
    /** @var int[] */
    public static array $attempts = [];

    /** @var Throwable[] */
    public static array $failed = [];

    /** @var string[] what ran, for jobs that only need to prove it */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
        self::$attempts = [];
        self::$failed = [];
    }
}
