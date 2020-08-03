<?php

return [
    'driver'   => 'stomp',
    'queue'    => env('STOMP_QUEUE', 'default'),
    'protocol' => env('STOMP_PROTOCOL', 'tcp'),
    'host'     => env('STOMP_HOST', '127.0.0.1'),
    'port'     => env('STOMP_PORT', 61613),
    'username' => env('STOMP_USERNAME', 'admin'),
    'password' => env('STOMP_PASSWORD', 'admin'),

    /*
     * Set to "horizon" if you wish to use Laravel Horizon.
     */
    'worker' => env('STOMP_WORKER', 'default'),
];
