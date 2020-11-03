<?php

namespace Voice\Stomp\Queue\Contracts;

interface HasRawData
{
    public function getRawData(): array;
}
