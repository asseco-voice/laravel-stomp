<?php

namespace Voice\Stomp;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Voice\Stomp\Horizon\StompQueue as HorizonStompQueue;
use Voice\Stomp\Queue\Connectors\StompConnector;
use Voice\Stomp\Queue\Stomp\ClientWrapper;
use Voice\Stomp\Queue\Stomp\ConfigWrapper;
use Voice\Stomp\Queue\Stomp\ConnectionWrapper;
use Voice\Stomp\Queue\StompQueue;

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
    }
}
