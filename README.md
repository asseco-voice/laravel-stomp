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

Subscribing with client acknowledgement option (ENV variables):

```
STOMP_CONSUMER_WIN_SIZE=819200      // number of bytes that Broker will send to client before it expects ACK
STOMP_CONSUMER_ACK_MODE=auto		// mode: client (ACK needs to be sent) | auto (no ACK, and window-size has to be -1 in that case)
```

Options for Laravel worker:

```
STOMP_CONSUMER_ALL_QUEUES = default;	// which queue name(s) represent that all queues from Config should be read
STOMP_READ_MESSAGE_DB_LOG = false		// write POP-ed events in DB table `stomp_event_logs`
```

You can see all other available ``.env`` variables, their defaults and usage explanation within 
the [config file](config/stomp.php). 

## Horizon

Laravel Horizon is **not supported**. Horizon only works with Redis queues: it calls Redis-only queue methods
such as ``readyNow()`` and ``clear()``, which a STOMP queue does not have (see issues #32 and #33). Run STOMP
workers with ``php artisan queue:work stomp`` under a process supervisor instead.

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

By default a job that throws is **not** retried and **not** written to ``failed_jobs``. The default ack mode is
``auto``, so the broker considers a message delivered as soon as the worker reads it; when the job then throws,
the worker can only "release" it, and with ``STOMP_FAILED_JOB_REQUEUE=false`` (the default) a release re-sends
nothing. The job's ``$tries``, ``backoff``, ``retryUntil()`` and ``failed()`` have no effect, and a job that calls
``$this->release()`` (or a middleware that does, such as ``WithoutOverlapping``) is dropped. The exception is only
reported to the log.

To get Laravel's retry behaviour for a job, opt in with ``RetriesOnStomp`` (see [Retries](#retries)).

For jobs that do not opt in, these ``env`` variables keep their old meaning:

- `STOMP_FAILED_JOB_REQUEUE` - defaults to `false`. When `true`, a released job is re-sent to the queue it came
from, using the two settings below rather than the job's own tries and backoff.
- `STOMP_AUTO_TRIES` - defaults to `true`: max tries is forced to 2. Attempts are counted from 0 on this path, so
a re-queued job runs 3 times before it fails. Set to `false` to use the job's `$tries` (or `--tries`); attempts are
still counted from 0, so `$tries = N` gives N+1 runs. Use ``RetriesOnStomp`` for Laravel's counting.
- `STOMP_AUTO_BACKOFF` - defaults to `true`: the re-send is delayed `attempt^STOMP_BACKOFF_MULTIPLIER` seconds
instead of the job's backoff.
- `STOMP_BACKOFF_MULTIPLIER` - defaults to `2`.

None of them has any effect while `STOMP_FAILED_JOB_REQUEUE` is `false`, and none of them applies to a job that
opts in to retries.

## Retries

A queued job, or a queued listener, gets Laravel's retry semantics by implementing
``Asseco\Stomp\Queue\Contracts\RetriesOnStomp`` (a marker interface, no methods). For a queued listener, put it
on the listener class. Only job and listener classes can opt in: a queued Mailable or Notification (sent through
``SendQueuedMailable`` / ``SendQueuedNotifications``) or a broadcast event cannot.

```php
use Asseco\Stomp\Queue\Contracts\RetriesOnStomp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class SyncContact implements ShouldQueue, RetriesOnStomp
{
    use InteractsWithQueue, Queueable;

    public $tries = 4;

    /** Seconds before attempts 2, 3 and 4. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        // ...
    }

    public function failed(Throwable $e): void
    {
        // runs once, after the last attempt
    }
}
```

What an opted-in job gets:

- Attempts count from 1, as in Laravel: ``$this->attempts()`` is 1 on the first run.
- ``$tries`` / ``tries()``, ``$backoff`` / ``backoff()``, ``retryUntil()`` and ``$maxExceptions`` work as documented
for Laravel queues. ``STOMP_AUTO_TRIES``, ``STOMP_AUTO_BACKOFF`` and ``STOMP_BACKOFF_MULTIPLIER`` are ignored for it.
- When it throws and has attempts left, or when it releases itself (``$this->release(30)``, ``WithoutOverlapping``,
rate limiting), its message is re-sent to the queue it came from with the next attempt number, delayed by the
backoff (or the release delay). This does not depend on ``STOMP_FAILED_JOB_REQUEUE``.
- When it gives up (last attempt, ``retryUntil()`` passed, ``$maxExceptions`` reached), ``failed()`` runs and a
``failed_jobs`` row is written by the configured failed job provider.
- The re-send waits for the broker's receipt. If the broker does not confirm it (an ERROR frame such as a missing
SEND permission or a full address, no receipt within stomp-php's receipt wait of 2 s, or a connection failure),
the job is failed with a ``RequeueFailedException`` ("Could not requeue ... for attempt N: <cause>"), so
``failed()`` runs and a ``failed_jobs`` row is written. The re-send is tried once, without a reconnect; the worker
reconnects on its next poll as usual.

Things to know:

- **Retried jobs must be safe to repeat.** A retry runs the job again from the start, including whatever it did
before it threw.
- **Opting in without ``$tries`` changes nothing about retries**: max tries then comes from ``queue:work --tries``,
which defaults to 1 (no retry). What does change is that ``failed()`` runs and a ``failed_jobs`` row is written.
- The delay is a broker scheduled delivery (``AMQ_SCHEDULED_DELAY``), so it needs a broker that supports it, such as
ActiveMQ or Artemis. A broker that ignores the header delivers the retry immediately.
- The re-send goes only to this service's queue, not to every queue on the address. The service needs SEND
permission on that queue's address; without it every retry fails the job (see above).
- ``$maxExceptions`` is counted in the worker's cache (as in Laravel). With a per-pod cache (array, file) each
worker counts on its own; use a shared store (redis, database) for one count per job.
- With ack mode ``auto`` a worker crash, OOM or kill while the job runs still loses the message. After the broker
answers a re-send with an ERROR frame it closes the connection, and messages it had already pushed to this worker
(auto-acked, prefetched) are lost on the reconnect, as on any reconnect today.
- Timeouts behave as before (``STOMP_FAIL_ON_TIMEOUT``, ``STOMP_TIMEOUT``). The re-send runs inside the job's
timeout window, so the receipt wait (up to 2 s) counts towards it.
- External (non-Laravel) ``stomp.*`` events are never re-sent by this feature.

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

In case you want to change
the default log manager, it can be done in the ``asseco-stomp`` config file. The new log manager must extend
``Illuminate\Log\LogManager``.


## Upgrade notes

- **Opt-in retries** (``RetriesOnStomp``) - see [Retries](#retries). Jobs that do not opt in behave as before.
- **Rolling deploys.** A worker still on the old version that picks up a retry sent by a new one treats it as a
job that does not opt in: ``auto_tries`` (max 2) applies to the attempt number in the message. A retry for
attempt 2 runs once more and is failed if it throws; one for attempt 3 or later is failed without running. Either
way ``failed()`` runs and a ``failed_jobs`` row is written: the retry is not lost, but it gets fewer attempts.
- **Re-sent messages no longer carry the received frame's headers** (``subscription``, ``message-id``, ``ack``,
``redelivered``, ``timestamp``, ...). Artemis copied them back on delivery, so a message re-queued by
``STOMP_FAILED_JOB_REQUEUE`` or ``queue:retry`` was dropped as a wrong frame by any worker other than the one
(and the session) that first received it. This applies to all three re-send paths.
- **Queued closures, queued artisan commands and jobs with a custom ``displayName()`` now run.** The driver used to
decide whether a native job could run from ``class_exists()`` on the payload ``displayName``. A job's
``displayName()`` can be anything: for ``Artisan::queue()`` it is the command name, and for a queued closure
(``CallQueuedClosure``) it is "Closure (file:line)". So those jobs were skipped (an error on the stomp log) and
acked while the worker printed ``DONE``. It now decides from the job's command class, as Laravel does. A message
whose class does not exist in the service (for example another service's job, broadcast event, listener,
mailable or notification on a shared address) is still skipped.
  **Closures and ``Artisan::queue()`` jobs have no class of their own, so nothing skips them in other services.**
  One pushed to an address that several services read (``eloquent::`` and the like) now runs in **every** service
  that consumes it: the closure in the wrong app, the command with another service's arguments (or failing with
  "command not found"). Dispatch closures and queued commands only to an address that this service alone reads.

## Usage

You can use library now like being native Laravel queue. 
For usage, you can check 
[official Laravel queue documentation](https://laravel.com/docs/8.x/queues).
