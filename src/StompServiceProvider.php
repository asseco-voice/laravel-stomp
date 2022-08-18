<?php

namespace Asseco\Stomp;

use Asseco\Stomp\Queue\Connectors\StompConnector;
use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\Stomp\Config;
use Asseco\Stomp\Queue\Stomp\ConnectionWrapper;
use Asseco\Stomp\Queue\StompQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\NullLogger;

class StompServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/asseco-stomp.php', 'asseco-stomp');

        $this->mergeConfigFrom(__DIR__ . '/../config/stomp.php', 'queue.connections.stomp');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        app()->singleton(Config::class);
        app()->singleton(ConnectionWrapper::class);
        app()->singleton(ClientWrapper::class);

        app()->singleton(StompQueue::class);

        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('stomp', function () {
            return new StompConnector($this->app['events']);
        });

        $logsEnabled = Config::get('enable_logs');

        app()->singleton('stompLog', function ($app) use ($logsEnabled) {
            $logManager = config('asseco-stomp.log_manager');

            return $logsEnabled ? new $logManager($app) : new NullLogger();
        });
    }
}
