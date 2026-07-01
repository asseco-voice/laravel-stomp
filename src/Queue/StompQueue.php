<?php

namespace Asseco\Stomp\Queue;

use Asseco\Stomp\Queue\Contracts\HasHeaders;
use Asseco\Stomp\Queue\Contracts\HasRawData;
use Asseco\Stomp\Queue\Jobs\StompJob;
use Asseco\Stomp\Queue\Stomp\ClientWrapper;
use Asseco\Stomp\Queue\Stomp\Config;
use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueInterface;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Stomp\Exception\ConnectionException;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;

class StompQueue extends Queue implements QueueInterface
{
    public const AMQ_QUEUE_SEPARATOR = '::';
    public const HEADERS_KEY = '_headers';

    const CORRELATION = 'X-Correlation-ID';

    const ACK_MODE_CLIENT = 'client';
    const ACK_MODE_AUTO = 'auto';

    /**
     * Stomp instance from stomp-php repo.
     */
    public StatefulStomp $client;

    public array $readQueues;
    public array $writeQueues;

    /**
     * List of queues already subscribed to. Preventing multiple same subscriptions.
     *
     * @var array
     */
    protected array $subscribedTo = [];

    protected LoggerInterface $log;
    protected string $session;

    /** @var null|Frame */
    protected $_lastFrame = null;

    protected string $_ackMode = '';

    protected array $_queueNamesForProcessAllQueues = [''];
    protected bool $_customReadQueusDefined = false;

    protected bool $_readMessagesLogToDb = false;

    public function __construct(ClientWrapper $stompClient)
    {
        $this->readQueues = $this->setReadQueues();
        $this->writeQueues = $this->setWriteQueues();
        $this->client = $stompClient->client;
        $this->log = app('stompLog');

        $this->session = $this->client->getClient()->getSessionId();

        $this->_ackMode = strtolower(Config::get('consumer_ack_mode') ?? self::ACK_MODE_AUTO);

        // specify which queue names should be considered as "All queues from Config"
        // "default" & ""
        $this->_queueNamesForProcessAllQueues = Config::queueNamesForProcessAllQueues();
        $this->_readMessagesLogToDb = Config::shouldReadMessagesBeLoggedToDB();
    }

    /**
     * Append queue name to topic/address to avoid random hashes in broker.
     *
     * @param  string|null  $queuesString
     * @return array
     */
    protected function setReadQueues(?string $queuesString = ''): array
    {
        $queuesString = $queuesString ?: Config::readQueues();
        $queues = $this->parseQueues($queuesString);

        foreach ($queues as &$queue) {
            $default = Config::defaultQueue();
            if (!str_contains($queue, self::AMQ_QUEUE_SEPARATOR)) {
                $queue .= self::AMQ_QUEUE_SEPARATOR . $default . '_' . substr(Str::uuid(), -5);
                continue;
            }
            if (Config::get('prepend_queues')) {
                $topic = Str::before($queue, self::AMQ_QUEUE_SEPARATOR);
                $queueName = Str::after($queue, self::AMQ_QUEUE_SEPARATOR);

                $queue = $topic . self::AMQ_QUEUE_SEPARATOR . "{$topic}_{$default}_{$queueName}";
            }
        }

        return $queues;
    }

    protected function setWriteQueues(): array
    {
        return $this->parseQueues(Config::writeQueues());
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        // Stomp library doesn't have this functionality
        return 0;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        // createPayload signature is ($job, $queue, $data); the args were previously swapped here.
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  mixed  $payload
     * @param  string|null  $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (!$payload instanceof Message) {
            $payload = $this->wrapStompMessage($payload);
        }

        $payload = $this->addCorrelationHeader($payload);

        $writeQueues = $queue ? $this->parseQueues($queue) : $this->writeQueues;

        return $this->writeToMultipleQueues($writeQueues, $payload);
    }

    /**
     * @param  Frame  $payload
     * @return mixed
     */
    protected function addCorrelationHeader($payload)
    {
        if (!$this->needsHeader($payload, self::CORRELATION)) {
            return $payload;
        }

        $header = Str::uuid()->toString();

        if (request()->hasHeader(self::CORRELATION)) {
            $header = request()->header(self::CORRELATION);
        }

        $payload->addHeaders([self::CORRELATION => $header]);

        return $payload;
    }

    /**
     * @param  Frame  $payload
     * @param  string  $header
     * @return bool
     */
    protected function needsHeader($payload, string $header): bool
    {
        $headers = $payload->getHeaders();

        return !Arr::has($headers, [$header]);
    }

    protected function wrapStompMessage(string $payload): Message
    {
        $decoded = json_decode($payload, true, 1024);
        $body = Arr::except($decoded, self::HEADERS_KEY);
        $body = $this->addMissingUuid($body);
        $headers = Arr::get($decoded, self::HEADERS_KEY, []);
        $headers = $this->forgetHeadersForRedelivery($headers);

        return new Message(json_encode($body, depth: 1024), $headers);
    }

    /**
     * @param  array  $writeQueues
     * @param  Message  $payload
     * @return bool
     */
    protected function writeToMultipleQueues(array $writeQueues, Message $payload): bool
    {
        /**
         * @var $payload Message
         */
        $this->log->info("$this->session [STOMP] Pushing stomp payload to queue: " . print_r([
            'body' => $payload->getBody(),
            'headers' => $payload->getHeaders(),
            'queues' => $writeQueues,
        ], true));

        $allEventsSent = true;

        foreach ($writeQueues as $writeQueue) {
            $sent = $this->write($writeQueue, $payload);

            if (!$sent) {
                $allEventsSent = false;
                $this->log->error("$this->session [STOMP] Message not sent on queue: $writeQueue");
                continue;
            }

            $this->log->info("$this->session [STOMP] Message sent on queue: $writeQueue");
        }

        return $allEventsSent;
    }

    protected function write($queue, Message $payload, $tryAgain = true): bool
    {
        // This will write all the events received in a single batch, then send disconnect frame
        try {
            $this->log->info("$this->session [STOMP] PUSH queue: '$queue'");
            $sent = $this->client->send($queue, $payload);
            $this->log->info("$this->session [STOMP] Message sent successfully? " . ($sent ? 't' : 'f'));

            return $sent;
        } catch (Exception $e) {
            $this->log->error("$this->session [STOMP] PUSH failed. Reconnecting... " . $e->getMessage());
            $this->reconnect(false);

            if ($tryAgain) {
                $this->log->info("$this->session [STOMP] Trying to send again...");

                return $this->write($queue, $payload, false);
            }

            return false;
        }
    }

    /**
     * Overridden to prevent double json encoding/decoding
     * Create a payload string from the given job and data.
     *
     * @param  $job
     * @param  $queue
     * @param  string  $data
     * @return Message
     */
    protected function createPayload($job, $queue, $data = '')
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $payload = $this->createPayloadArray($job, $queue, $data);
        $payload = $this->addMissingUuid($payload);
        $headers = $this->getHeaders($job);
        $headers = $this->forgetHeadersForRedelivery($headers);

        $message = new Message(json_encode($payload, depth: 1024), $headers);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: ' . json_last_error()
            );
        }

        return $message;
    }

    /**
     * Overridden to support raw data
     * Create a payload array from the given job and data.
     *
     * @param  object|string  $job
     * @param  string  $queue
     * @param  string  $data
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        if ($this->hasEvent($job) && $job->event instanceof HasRawData) {
            return $job->event->getRawData();
        }

        if ($job instanceof HasRawData) {
            return $job->getRawData();
        }

        return parent::createPayloadArray($job, $queue, $data);
    }

    protected function addMissingUuid(array $payload): array
    {
        if (!Arr::has($payload, 'uuid')) {
            $payload['uuid'] = (string) Str::uuid();
        }

        return $payload;
    }

    protected function getHeaders($job)
    {
        if ($this->hasEvent($job) && $job->event instanceof HasHeaders) {
            return $job->event->getHeaders();
        }

        if ($job instanceof HasHeaders) {
            return $job->getHeaders();
        }

        return [];
    }

    protected function hasEvent($job): bool
    {
        return $job instanceof BroadcastEvent && property_exists($job, 'event');
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return Job|null
     */
    public function pop($queue = null)
    {
        $this->setReadQueuesForWorker($queue);

        $this->ackLastFrameIfNecessary();

        $frame = $this->read($queue);

        if (!($frame instanceof Frame)) {
            return null;
        }

        $this->addCorrelationHeader($frame);

        $this->log->info("$this->session [STOMP] Popped a job (frame) from queue: " . print_r($frame, true));

        // This might no longer be relevant. It would happen if pop read CONNECTED frame instead of
        // MESSAGE one, which was a bug introduced at one point. Keeping it as safety measure
        $queueFromFrame = $this->getQueueFromFrame($frame);

        if (!$queueFromFrame) {
            $this->log->warning("$this->session [STOMP] Wrong frame received. Expected MESSAGE, got: " . print_r($frame, true));
            $this->_lastFrame = null;

            return null;
        }

        $this->_lastFrame = $frame;

        $this->writeMessageToDBIfNeeded($frame, $queueFromFrame);

        return new StompJob($this->container, $this, $frame, $queueFromFrame);
    }

    protected function read($queue)
    {
        try {
            $this->log->info("$this->session [STOMP] POP");

            $this->heartbeat();
            $this->subscribeToQueues();

            $frame = $this->client->read();
            $this->log->info("$this->session [STOMP] Message read!");

            return $frame;
        } catch (Exception $e) {
            // Single bounded retry: reconnect once, then attempt one more read. Reconnect()
            // itself does the backed-off retry loop and throws if it can't recover, which
            // propagates out and stops the worker (supervisor restarts it). The previous
            // unbounded `return $this->read($queue)` recursion could exhaust the stack on a
            // down broker and lose the in-flight frame.
            $this->log->error("$this->session [STOMP] Failed to read from '$queue': " . $e->getMessage() . " — reconnecting once.");
            $this->reconnect();

            $this->log->info("$this->session [STOMP] Re-reading after reconnect...");

            return $this->client->read();
        }
    }

    protected function parseQueues(string $queue): array
    {
        return explode(';', $queue);
    }

    protected function getQueueFromFrame(Frame $frame): ?string
    {
        // This will return bool when accepting things like CONNECTED frame, we'd just want to
        $subscription = $this->client->getSubscriptions()->getSubscription($frame);

        return optional($subscription)->getDestination();
    }

    public function makeDelayHeader(int $delay): array
    {
        // TODO: remove ActiveMq hard coding
        return ['AMQ_SCHEDULED_DELAY' => $delay * 1000];
    }

    /**
     * If these values are left in the header, it will screw up the whole event redelivery
     * so we need to remove them before sending back to queue.
     *
     * @param  array  $headers
     * @return array
     */
    public function forgetHeadersForRedelivery(array $headers): array
    {
        // TODO: remove ActiveMq hard coding
        $keys = array_keys($headers);
        $amqMatches = preg_grep('/_AMQ.*/i', $keys);

        Arr::forget($headers, array_merge($amqMatches, ['content-length']));

        return $headers;
    }

    /**
     * @throws ConnectionException
     */
    protected function heartbeat(): void
    {
        $this->client->getClient()->getConnection()->sendAlive();
        $this->log->info("$this->session [STOMP] Sent alive to {$this->client->getClient()->getSessionId()}");
    }

    /**
     * Re-establish the broker connection with bounded, backed-off retries.
     *
     * Replaces the old recursive circuit-breaker, which (a) never reset its counter,
     * (b) slept only 100us between tries, and (c) on give-up just returned, leaving the
     * worker spinning as a "running" but permanently-detached consumer. We now retry with real
     * exponential backoff + jitter, reset implicitly on success, and THROW on final
     * give-up so the queue worker exits and the supervisor restarts a clean process.
     *
     * @throws ConnectionException when reconnection fails after the configured tries
     */
    protected function reconnect(bool $subscribe = true): void
    {
        $this->log->info("$this->session [STOMP] Reconnecting...");

        $this->disconnect();

        // a fresh session has no subscriptions; clear the cache so subscribeToQueues()
        // actually re-subscribes (the old code only cleared this on the subscribe path,
        // so a write-triggered reconnect left a consumer silently un-subscribed).
        $this->subscribedTo = [];

        $maxTries = $this->reconnectMaxTries();
        $baseMs = (int) (Config::get('reconnect_backoff_base_ms') ?? 200);
        $maxMs = (int) (Config::get('reconnect_backoff_max_ms') ?? 30000);

        for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
            try {
                $this->client->getClient()->connect();
                $this->session = $this->client->getClient()->getSessionId();
                $this->log->info("$this->session [STOMP] Reconnected successfully on attempt $attempt.");

                if ($subscribe && $this->client->getClient()->isConnected()) {
                    $this->log->info("$this->session [STOMP] Connected, subscribing...");
                    $this->subscribeToQueues();
                }

                return;
            } catch (Exception $e) {
                $sleepMs = self::reconnectBackoffMs($attempt, $baseMs, $maxMs) + random_int(0, 250);
                $this->log->error("$this->session [STOMP] Reconnect attempt $attempt/$maxTries failed: "
                    . $e->getMessage() . " — retrying in {$sleepMs}ms");
                usleep($sleepMs * 1000);
            }
        }

        // Out of tries: throw so the worker stops and the supervisor restarts it fresh,
        // rather than silently looping forever as a zombie that consumes nothing.
        $msg = "$this->session [STOMP] Reconnect failed after $maxTries attempts; aborting worker.";
        $this->log->error($msg);

        throw new ConnectionException($msg);
    }

    protected function reconnectMaxTries(): int
    {
        $tries = (int) (Config::get('reconnect_tries') ?? 10);

        return $tries > 0 ? $tries : 1;
    }

    /**
     * Capped exponential backoff (milliseconds) for reconnect attempt N (1-based).
     * Pure/deterministic (jitter is added separately at the call site) so it can be
     * unit-tested without a broker.
     */
    public static function reconnectBackoffMs(int $attempt, int $baseMs, int $maxMs): int
    {
        if ($attempt < 1) {
            $attempt = 1;
        }

        $delay = $baseMs * (2 ** ($attempt - 1));

        return (int) min($maxMs, $delay);
    }

    public function disconnect()
    {
        if (!$this->client->getClient()->isConnected()) {
            return;
        }

        try {
            $this->ackLastFrameIfNecessary();
            $this->log->info("$this->session [STOMP] Disconnecting...");
            $this->client->getClient()->disconnect();
        } catch (Exception $e) {
            $this->log->info("$this->session [STOMP] Failed disconnecting: " . print_r($e->getMessage(), true));
        }
    }

    /**
     * Subscribe to queues.
     *
     * @return void
     */
    protected function subscribeToQueues(): void
    {
        $winSize = Config::get('consumer_window_size');
        if ($this->_ackMode != self::ACK_MODE_CLIENT) {
            // New Artemis version can't work without this as it will consume only first message otherwise.
            $winSize = -1;
        }

        foreach ($this->readQueues as $queue) {
            $alreadySubscribed = in_array($queue, $this->subscribedTo);

            if ($alreadySubscribed) {
                continue;
            }

            $this->log->info("$this->session [STOMP] subscribeToQueue `$queue` with ack-mode: {$this->_ackMode} & window-size: $winSize");

            $this->client->subscribe($queue, null, $this->_ackMode, [
                // we can define this if we are using ack mode = client
                'consumer-window-size' => (string) $winSize,
            ]);

            $this->subscribedTo[] = $queue;
        }
    }

    /**
     * If ack mode = client, and we have last frame - send ACK.
     *
     * @return void
     */
    public function ackLastFrameIfNecessary()
    {
        if ($this->_ackMode == self::ACK_MODE_CLIENT && $this->_lastFrame) {
            $this->log->debug("$this->session [STOMP] ACK-ing last frame. Msg #" . $this->_lastFrame->getMessageId());
            $this->client->ack($this->_lastFrame);
            $this->_lastFrame = null;
        }
    }

    /**
     * Set read queues for queue worker, if queue parameter is defined
     * > php artisan queue:work --queue=eloquent::live30.
     *
     * @param  $queue
     * @return void
     */
    protected function setReadQueuesForWorker($queue)
    {
        if ($this->_customReadQueusDefined) {
            // already setup
            return;
        }

        $queue = (string) $queue;
        if (!in_array($queue, $this->_queueNamesForProcessAllQueues)) {
            // one or more queue
            $this->readQueues = $this->setReadQueues($queue);
        }

        $this->_customReadQueusDefined = true;
    }

    protected function writeMessageToDBIfNeeded(Frame $frame, $queueFromFrame)
    {
        if ($this->_readMessagesLogToDb) {
            DB::table('stomp_event_logs')->insert(
                [
                    'session_id' => $this->session,
                    'queue_name' => $queueFromFrame,
                    'subscription_id' => $frame['subscription'],
                    'message_id' => $frame->getMessageId(),
                    'payload' => print_r($frame, true),
                    'created_at' => date('Y-m-d H:i:s.u'),
                ]
            );
        }
    }
}
