<?php

namespace Asseco\Stomp\Queue\Contracts;

interface HasRawData
{
    public function getRawData(): array;
}
