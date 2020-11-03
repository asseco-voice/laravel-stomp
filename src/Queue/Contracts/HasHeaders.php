<?php

namespace Voice\Stomp\Queue\Contracts;

interface HasHeaders
{
    public function getHeaders(): array;
}
