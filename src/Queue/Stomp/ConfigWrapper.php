<?php

namespace Voice\Stomp\Queue\Stomp;

use Illuminate\Support\Facades\Config;

class ConfigWrapper
{
    const CONFIG_ARRAY_PATH = 'queue.connections.stomp.';

    public static function get($key)
    {
        return Config::get(self::CONFIG_ARRAY_PATH . $key);
    }
}
