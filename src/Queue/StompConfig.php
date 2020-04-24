<?php

namespace Norgul\Stomp\Queue;

use Illuminate\Support\Facades\Config;

class StompConfig
{
    const CONFIG_ARRAY_PATH = 'queue.connections.stomp.';

    public static function get($key)
    {
        return Config::get(self::CONFIG_ARRAY_PATH . $key);
    }
}
