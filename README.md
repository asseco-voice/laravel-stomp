# Laravel Stomp driver

This package enables usage of Stomp driver for queueing natively inside 
Laravel.

PHP min version: 7.4.

Laravel 7.4.

## Installation

Package is installed through composer and is automatically registered
as a Laravel service provider.

``composer require norgul/laravel-stomp``

In order to connect it to your queue you need to change queue
connection driver in ``.env`` file:

```
QUEUE_CONNECTION=stomp
```

``.env`` variables and their explanation:

```
STOMP_QUEUE         queue name
STOMP_PROTOCOL      protocol (defaults to TCP)
STOMP_HOST          broker host
STOMP_PORT          port where STOMP is exposed in your broker
STOMP_USERNAME      broker username
STOMP_PASSWORD      broker password
STOMP_VHOST         broker vhost
```

If you take a look at libraries inner config you will see that
every value has its default, so its not necessary to override
all env keys if some values are matching. 

Config file is automatically served so you don't need to explicitly
add new keys to ``queue.php``, however if you should ever need to
change how it behaves you can always include it by adding the 
following under connections key:

```
'stomp' => [
    'driver'   => 'stomp',
    'queue'    => env('STOMP_QUEUE', 'default'),
    'protocol' => env('STOMP_PROTOCOL', 'tcp'),
    'host'     => env('STOMP_HOST', '127.0.0.1'),
    'port'     => env('STOMP_PORT', 61613),
    'username' => env('STOMP_USERNAME', 'admin'),
    'password' => env('STOMP_PASSWORD', 'admin'),
    'vhost'    => env('STOMP_VHOST', '/'),
],
```

These are at the same time env variables you can use inside `.env`
file to override default values. Since every value (in default
configuration) has its own default, you can override only what's 
different in your configuration, so doing this in ``.env`` is 
valid as well:

```
STOMP_QUEUE=myQueue
STOMP_PASSWORD=pa$$word123
```

Notice that unexpected things may happen if you decide to override 
config values within ``queue.php`` by adding for example:

```
'stomp' => [
    'host'     => '127.1.1.1',
],
```

This will override default logic to fetch value from ``.env``, thus
making this take precedence before default config and ``.env`` 
so host will effectively be set to ``127.1.1.1`` independently of
whether you actually have ``STOMP_HOST`` set or not. 

That being said, it is best that you don't override config as 
everything needed by library to run is exposed via env variables.
