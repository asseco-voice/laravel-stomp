<?php

use Stomp\Protocol\Version;

return [

    'driver' => 'stomp',
    'read_queues' => env('STOMP_READ_QUEUES'),
    'write_queues' => env('STOMP_WRITE_QUEUES'),
    'protocol' => env('STOMP_PROTOCOL', 'tcp'),
    'host' => env('STOMP_HOST', '127.0.0.1'),
    'port' => env('STOMP_PORT', 61613),
    'username' => env('STOMP_USERNAME', 'admin'),
    'password' => env('STOMP_PASSWORD', 'admin'),

    /**
     * Set to "horizon" if you wish to use Laravel Horizon.
     */
    'worker' => env('STOMP_WORKER', 'default'),

    /**
     * Calculate tries and backoff automatically without the need to specify it
     * in the queue work command.
     */
    'auto_tries' => env('STOMP_AUTO_TRIES', true),
    'auto_backoff' => env('STOMP_AUTO_BACKOFF', true),

    /*
     * Will failed job be re-queued ?
     * We experienced issues with pushing Jobs back to the topic/queue, so we're turning this OFF
    */
    'fail_job_requeue' => env('STOMP_FAILED_JOB_REQUEUE', false),

    /** If all messages should fail on timeout. Set to false in order to revert to default (looking in event payload) */
    'fail_on_timeout' => env('STOMP_FAIL_ON_TIMEOUT', true),

    /**
     * Maximum time in seconds for job execution. This value must be less than send heartbeat in order to run correctly.
     */
    'timeout' => env('STOMP_TIMEOUT', 45),

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

    /**
     * What will be appended as the queue if only address/topic is defined.
     * This is done to ensure that service doesn't connect to a broker with
     * hash as queue name. In case of multiple services connecting in such
     * a way, it becomes unclear which queue is from which service.
     */
    'default_queue' => env('STOMP_DEFAULT_QUEUE'),

    'enable_read_events_DB_logs' => env('STOMP_READ_MESSAGE_DB_LOG', false) === true,

    /**
     * Use Laravel logger for outputting logs.
     */
    'enable_logs' => env('STOMP_LOGS', false) === true,

    /**
     * Should the read queues be prepended. Useful for i.e. Artemis where queue
     * name is unique across whole broker instance. This will thus add some
     * uniqueness to the queues.
     */
    'prepend_queues' => true,

    /**
     * Heartbeat (ms) we ask the SERVER to send us. Default is now non-zero so the client
     * can detect a half-open / dropped connection (e.g. an idle connection reaped by a
     * load balancer or NAT) instead of only finding out on the next failed write
     * ("Was not possible to write frame!"). 0 disables server heartbeats.
     */
    'receive_heartbeat' => env('STOMP_RECEIVE_HEARTBEAT', 20000),

    /**
     * Heartbeat (ms) we promise to send the server. The broker derives the connection TTL
     * from this (Artemis: TTL = send_heartbeat * heartBeatToConnectionTtlModifier, default 2x).
     * IMPORTANT: keep the job `timeout` comfortably below that TTL — heartbeats are only
     * flushed between polls, so a long-running job does not heartbeat while it runs.
     */
    'send_heartbeat' => env('STOMP_SEND_HEARTBEAT', 50000),

    /**
     * Reconnect resilience. On a read/write failure the driver reconnects with capped
     * exponential backoff + jitter, retrying up to `reconnect_tries`. If it still can't
     * connect it throws, so the queue worker exits and the supervisor restarts a clean
     * process (rather than spinning forever as a detached, non-consuming worker).
     */
    'reconnect_tries' => env('STOMP_RECONNECT_TRIES', 10),
    'reconnect_backoff_base_ms' => env('STOMP_RECONNECT_BACKOFF_BASE_MS', 200),
    'reconnect_backoff_max_ms' => env('STOMP_RECONNECT_BACKOFF_MAX_MS', 30000),

    /**
     * Socket read timeout in seconds (stomp-php Connection::setReadTimeout). Default 0
     * keeps the historical non-blocking poll behaviour; set > 0 to let stomp-php service
     * heartbeats during idle reads.
     */
    'read_timeout' => env('STOMP_READ_TIMEOUT', 0),

    /**
     * Setting consumer-window-size to a value greater than 0 will allow it to receive messages until
     * the cumulative bytes of those messages reaches the configured size.
     * Once that happens the client will not receive any more messages until it sends the appropriate ACK or NACK
     * frame for the messages it already has.
     */
    'consumer_window_size' => env('STOMP_CONSUMER_WIN_SIZE', 8192000),

    /**
     * Subscribe mode: auto, client.
     */
    'consumer_ack_mode' => env('STOMP_CONSUMER_ACK_MODE', 'auto'),

    /**
     * Queue name(s) that represent that all queues should be read
     * If no queue is specified, Laravel puts 'default' - so this should be entered here.
     */
    'worker_queue_name_all' => explode(';', env('STOMP_CONSUMER_ALL_QUEUES', 'default;')),

    /**
     * Array of supported versions.
     */
    'version' => [Version::VERSION_1_2],
];
