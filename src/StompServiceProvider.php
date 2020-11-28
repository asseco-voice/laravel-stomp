<?php

namespace Asseco\Stomp;

use Illuminate\Log\LogManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\NullLogger;
use Asseco\Stomp\Horizon\StompQueue as HorizonStompQueue;
use Asseco\Stomp\Queue\Connectors\StompConnector;
use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\Stomp\ConfigWrapper;
use Asseco\Stomp\Queue\Stomp\ConnectionWrapper;
use Asseco\Stomp\Queue\StompQueue;

class StompServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/stomp.php', 'queue.connections.stomp');

        if (ConfigWrapper::get('worker') == 'horizon') {
            $this->mergeConfigFrom(__DIR__ . '/../config/horizon.php', 'horizon');
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->singleton(ConfigWrapper::class);
        $this->app->singleton(ConnectionWrapper::class);
        $this->app->singleton(ClientWrapper::class);

        $this->app->singleton(StompQueue::class);
        $this->app->singleton(HorizonStompQueue::class);

        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('stomp', function () {
            return new StompConnector($this->app['events']);
        });

        $logsEnabled = ConfigWrapper::get('enable_logs');

        $this->app->singleton('stompLog', function ($app) use ($logsEnabled) {
            return $logsEnabled ? new LogManager($app) : new NullLogger();
        });
    }
}
