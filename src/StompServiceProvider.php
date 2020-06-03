<?php

namespace Voice\Stomp;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Voice\Stomp\Queue\Connectors\StompConnector;

class StompServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/stomp.php', 'queue.connections.stomp');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('stomp', function () {
            return new StompConnector($this->app['events']);
        });
    }
}
