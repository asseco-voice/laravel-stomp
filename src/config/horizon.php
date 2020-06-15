<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'stomp',
                'queue' => [env('STOMP_QUEUE', 'default')],
                'balance' => 'simple',
                'processes' => 10,
                'tries' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'stomp',
                'queue' => [env('STOMP_QUEUE', 'default')],
                'balance' => 'simple',
                'processes' => 3,
                'tries' => 1,
            ],
        ],
    ],
];
