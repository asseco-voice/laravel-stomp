<?php

namespace Asseco\Stomp\Queue\Contracts;

interface HasHeaders
{
    public function getHeaders(): array;
}
