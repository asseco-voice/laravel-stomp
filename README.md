<p align="center"><a href="https://see.asseco.com" target="_blank"><img src="https://github.com/asseco-voice/art/blob/main/evil_logo.png" width="500"></a></p>

# Laravel Stomp driver

This package enables usage of Stomp driver for queueing natively inside Laravel.

## Installation

Package is installed through composer and is automatically registered
as a Laravel service provider.

``composer require asseco-voice/laravel-stomp``

In order to connect it to your queue you need to change queue
connection driver in ``.env`` file:

```
QUEUE_CONNECTION=stomp
```

Connection variables:

```
STOMP_PROTOCOL      protocol (defaults to TCP)
STOMP_HOST          broker host (defaults to 127.0.0.1)
STOMP_PORT          port where STOMP is exposed in your broker (defaults to 61613)
STOMP_USERNAME      broker username (defaults to admin)
STOMP_PASSWORD      broker password (defaults to admin)
```

You can subscribe to queues to read from or to write to with:

```
STOMP_READ_QUEUES=...
STOMP_WRITE_QUEUES=...
```

Both have same nomenclature when it comes to subscribing:
```
topic                        <-- will read from all queues on the topic
topic::queue1                <-- will read only from queue1 on the topic
topic::queue1;topic::queue2  <-- will read from queue1 and queue2 on the topic
```

You can see all other available ``.env`` variables, their defaults and usage explanation within 
the [config file](config/stomp.php). 

If ``horizon`` is used as worker, library will work side-by-side with 
[Laravel Horizon](https://laravel.com/docs/7.x/horizon) and basic configuration will be 
automatically resolved:

```
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'stomp',
            'queue' => [env('STOMP_READ_QUEUES', 'default')],
            ...
        ],
    ],

    'local' => [
        'supervisor-1' => [
            'connection' => 'stomp',
            'queue' => [env('STOMP_READ_QUEUES', 'default')],
            ...
        ],
    ],
],
```

If you need a custom configuration, publish Horizon config (check Horizon documentation)
and adapt to your needs. 

## Non-Laravel events

It is possible to handle outside events as well. By default, if event is not a standard Laravel event it 
gets re-thrown as a ``stomp.*`` event with payload it received. 

If the frame you received belongs to a ``topic::test_queue`` queue, system will throw a `stomp.topic.test_queue` event,
otherwise if for some reason the queue name can't be parsed it will dispatch a ``stomp.event`` event. 

You can listen to it by including this in 
``EventServiceProvider::boot()``:

```
Event::listen('stomp.*', function ($event, $payload) {
    ...
});
```

## Failed jobs

For the sake of simplicity and brevity ``StompJob`` class is defined in a way to utilize Laravel tries and
backoff properties out of the box ([official documentation](https://laravel.com/docs/8.x/queues#dealing-with-failed-jobs)). 

Upon failure, jobs will retry 5 times before being written to ``failed_jobs`` table.

Each subsequent attempt will be tried in ``attempt^2`` seconds, meaning if it is a third attempt, it will retry in 9s after 
the previous job failure. 

Note that **job properties by default have precedence over CLI commands**, thus with these defaults in place
the flags ``--tries`` and `--backoff` will be overridden. 

You can turn off this behavior with following ``env`` variables:

- `STOMP_AUTO_TRIES` - defaults to `true`. Set to `false` to revert to Laravel default of `0` retries. 
- `STOMP_AUTO_BACKOFF` - defaults to `true`. Set to `false` to revert to Laravel default of `0s` backoff. 
- `STOMP_BACKOFF_MULTIPLIER` - defaults to `2`. Does nothing if `STOMP_AUTO_BACKOFF` is turned off. Increase to 
make even bigger interval between two failed jobs. 

Job will be re-queued to the queue it came from.

### Headers

Due to the fact that Laravel doesn't save event headers to ``failed_jobs`` table, this package is circumventing this
by storing headers as a part of the payload in ``_headers`` key. This is all done automatically behind the scenes
so no interaction is needed with it. 

If you want to append additional headers to your events, you can do so by implementing `HasHeaders` interface.

## Raw data

In case you need your service to communicate with external non-Laravel services it is possible to circumvent 
Laravel event wrapping by implementing ``HasRawData`` interface. This will enable you to provide a custom 
payload to the broker.

## Logs

Logs are turned off by default. You can include them by setting env key ``STOMP_LOGS=true``.

## Usage

You can use library now like being native Laravel queue. 
For usage, you can check 
[official Laravel queue documentation](https://laravel.com/docs/8.x/queues).
