<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support\Retries;

class SomethingHappened
{
    public function __construct(public string $what = 'it')
    {
    }
}
