<?php

namespace Asseco\Stomp\Queue\Stomp;

use Illuminate\Support\Str;

class Config
{
    const CONFIG_ARRAY_PATH = 'queue.connections.stomp.';

    public static function get($key)
    {
        return config(self::CONFIG_ARRAY_PATH . $key);
    }

    public static function defaultQueue(): string
    {
        return self::get('default_queue') ?: self::appName();
    }

    public static function readQueues(): string
    {
        return self::get('read_queues') ?: self::appName();
    }

    public static function writeQueues(): string
    {
        return self::get('write_queues') ?: self::appName();
    }

    protected static function appName(): string
    {
        return Str::snake(config('app.name', 'localhost'));
    }
}
