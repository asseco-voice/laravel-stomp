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

``.env`` variables you can override:

```
STOMP_READ_QUEUES   queue(s) name to read from (defaults to 'default')
STOMP_WRITE_QUEUE   queue name to write to (defaults to first read queue)
STOMP_PROTOCOL      protocol (defaults to TCP)
STOMP_HOST          broker host (defaults to 127.0.0.1)
STOMP_PORT          port where STOMP is exposed in your broker (defaults to 61613)
STOMP_USERNAME      broker username (defaults to admin)
STOMP_PASSWORD      broker password (defaults to admin)
STOMP_WORKER        job worker to be used (defaults to 'default' can be 'horizon')
```

If you want to subscribe on multiple queues, use ``;`` as a separator between them: `STOMP_READ_QUEUES=queue1;queue2`

The package will always write to a single queue, so be sure to include a
``STOMP_WRITE_QUEUE`` in case `STOMP_READ_QUEUES` has multiple queues to read from.

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

## Usage

You can use library now like being native Laravel queue. 
For usage, you can check 
[official Laravel queue documentation](https://laravel.com/docs/7.x/queues).
