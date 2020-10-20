<?php

return [

    'driver'             => 'stomp',
    'read_queues'        => env('STOMP_READ_QUEUES', 'default'),
    'write_queue'        => env('STOMP_WRITE_QUEUE', env('STOMP_READ_QUEUES', 'default')),
    'protocol'           => env('STOMP_PROTOCOL', 'tcp'),
    'host'               => env('STOMP_HOST', '127.0.0.1'),
    'port'               => env('STOMP_PORT', 61613),
    'username'           => env('STOMP_USERNAME', 'admin'),
    'password'           => env('STOMP_PASSWORD', 'admin'),

    /**
     * Set to "horizon" if you wish to use Laravel Horizon.
     */
    'worker'             => env('STOMP_WORKER', 'default'),

    /**
     * Calculate tries and backoff automatically without the need to specify it
     * in the queue work command.
     */
    'auto_tries'         => env('STOMP_AUTO_TRIES', true),
    'auto_backoff'       => env('STOMP_AUTO_BACKOFF', true),

    /**
     * Incremental multiplier for failed job redelivery.
     * Multiplier 2 means that the rescheduled job will be repeated in attempt^2 seconds.
     *
     * For 3 tries this means:
     *
     * attempt 2 - 4s
     * attempt 3 - 9s
     * attempt 4 - 16s
     */
    'backoff_multiplier' => env('STOMP_BACKOFF_MULTIPLIER', 2),
];
