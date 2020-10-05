<?php

return [

    'driver'      => 'stomp',
    'read_queues' => env('STOMP_READ_QUEUES', 'default'),
    'write_queue' => env('STOMP_WRITE_QUEUE', env('STOMP_READ_QUEUES', 'default')),
    'protocol'    => env('STOMP_PROTOCOL', 'tcp'),
    'host'        => env('STOMP_HOST', '127.0.0.1'),
    'port'        => env('STOMP_PORT', 61613),
    'username'    => env('STOMP_USERNAME', 'admin'),
    'password'    => env('STOMP_PASSWORD', 'admin'),

    /*
     * Set to "horizon" if you wish to use Laravel Horizon.
     */
    'worker'      => env('STOMP_WORKER', 'default'),
];
