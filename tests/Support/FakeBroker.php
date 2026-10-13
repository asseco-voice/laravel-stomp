<?php

declare(strict_types=1);

namespace Asseco\Stomp\Tests\Support;

/**
 * Minimal STOMP broker in a forked child process: accepts one client, answers CONNECT with
 * CONNECTED, and answers each SUBSCRIBE with one MESSAGE carrying `$messageBody` (if given).
 * kill() takes it down hard, like a broker restart; start() on the same port brings it back.
 */
class FakeBroker
{
    private function __construct(public readonly int $port, private int $pid, private $server)
    {
    }

    public static function start(int $port = 0, ?string $messageBody = null): self
    {
        $server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
        if (!$server) {
            throw new \RuntimeException("FakeBroker cannot listen on $port: $error");
        }
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('FakeBroker cannot fork');
        }
        if ($pid === 0) {
            self::serve($server, $messageBody);
        }

        return new self($port, $pid, $server);
    }

    public function kill(): void
    {
        posix_kill($this->pid, SIGKILL);
        pcntl_waitpid($this->pid, $status);
        fclose($this->server);
    }

    private static function serve($server, ?string $messageBody): never
    {
        $client = @stream_socket_accept($server, 10);
        $buffer = '';

        while ($client && ($chunk = fread($client, 8192)) !== false && $chunk !== '') {
            $buffer .= $chunk;

            while (($end = strpos($buffer, "\0")) !== false) {
                $frame = ltrim(substr($buffer, 0, $end), "\r\n");
                $buffer = substr($buffer, $end + 1);
                $command = strtok($frame, "\n");

                if ($command === 'CONNECT' || $command === 'STOMP') {
                    fwrite($client, "CONNECTED\nversion:1.2\nsession:fake-session\nheart-beat:0,0\n\n\0");
                } elseif ($command === 'SUBSCRIBE' && $messageBody !== null) {
                    preg_match('/^id:(.*)$/m', $frame, $id);
                    preg_match('/^destination:(.*)$/m', $frame, $destination);
                    fwrite($client, "MESSAGE\nsubscription:{$id[1]}\nmessage-id:fake-1\n"
                        . "destination:{$destination[1]}\ncontent-length:" . strlen($messageBody) . "\n\n$messageBody\0");
                }
            }
        }

        // never return into PHPUnit inside the child; SIGKILL skips its shutdown handlers
        posix_kill(getmypid(), SIGKILL);
        exit(1);
    }
}
