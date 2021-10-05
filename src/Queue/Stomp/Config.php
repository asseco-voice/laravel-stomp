<?php

namespace Asseco\Stomp\Queue\Stomp;

class Config
{
    const CONFIG_ARRAY_PATH = 'queue.connections.stomp.';

    public static function get($key)
    {
        return config(self::CONFIG_ARRAY_PATH . $key);
    }
}
