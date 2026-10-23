<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration\Support;

use Socket;
use Swoole\Coroutine;

/**
 * A TCP proxy in front of a real InfluxDB server that drops the next requests sent through it.
 *
 * A request the proxy drops is read and its connection closed without an answer, as a server does
 * that restarts or loses its connection, so the client sees a lost transport. Every other request
 * is passed to the server unchanged, and a client opens a new connection for the one after a drop.
 * Run it in the test's coroutine, where the socket functions yield to the other coroutines.
 */
final class FaultyProxy
{
    /**
     * The connections accepted so far.
     */
    public int $connections = 0;

    /**
     * The HTTP requests that reached the server.
     */
    public int $forwarded = 0;

    private ?Socket $listener = null;

    /**
     * The sockets of the connections being passed on, closed by stop() so that no coroutine waits on them.
     *
     * @var array<int, Socket>
     */
    private array $open = [];

    /**
     * @param int $drop the number of requests to drop before passing the rest on
     */
    public function __construct(
        private readonly string $upstreamHost,
        private readonly int $upstreamPort,
        private int $drop = 0,
    ) {
    }

    /**
     * Drop the next given number of requests.
     */
    public function drop(int $requests): void
    {
        $this->drop = $requests;
    }

    /**
     * Listen on the given port, or a free one when it is 0, and return the port.
     */
    public function start(int $port = 0): int
    {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($listener, '127.0.0.1', $port);
        socket_listen($listener, 128);
        socket_getsockname($listener, $address, $port);

        $this->listener = $listener;

        Coroutine::create(function () use ($listener): void {
            while ($this->listener === $listener && ($client = @socket_accept($listener)) instanceof Socket) {
                ++$this->connections;

                Coroutine::create(fn () => $this->serve($client));
            }
        });

        return $port;
    }

    /**
     * Stop listening and close every connection still open.
     */
    public function stop(): void
    {
        if ($this->listener !== null) {
            $listener = $this->listener;
            $this->listener = null;

            $this->close($listener);
        }

        foreach ($this->open as $socket) {
            $this->close($socket);
        }

        $this->open = [];
    }

    private function serve(Socket $client): void
    {
        $upstream = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (! @socket_connect($upstream, $this->upstreamHost, $this->upstreamPort)) {
            socket_close($client);
            socket_close($upstream);

            return;
        }

        $this->open[spl_object_id($client)] = $client;
        $this->open[spl_object_id($upstream)] = $upstream;

        Coroutine::create(function () use ($client, $upstream): void {
            $this->pipe($upstream, $client, false);
        });

        $this->pipe($client, $upstream, true);
    }

    private function pipe(Socket $from, Socket $to, bool $requests): void
    {
        while (@socket_recv($from, $data, 65_536, 0) > 0) {
            if ($requests) {
                if ($this->drop > 0) {
                    --$this->drop;

                    break;
                }

                $this->forwarded += preg_match_all('#^[A-Z]+ \S+ HTTP/1\.[01]\r$#m', $data);
            }

            if (@socket_write($to, $data) === false) {
                break;
            }
        }

        unset($this->open[spl_object_id($from)], $this->open[spl_object_id($to)]);

        $this->close($from);
        $this->close($to);
    }

    /**
     * Close a socket, waking any coroutine that waits on it.
     */
    private function close(Socket $socket): void
    {
        @socket_shutdown($socket);
        @socket_close($socket);
    }
}
