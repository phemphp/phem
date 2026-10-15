<?php

declare(strict_types=1);

namespace Rephp\Server;

use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Io\Poll\Watcher;
use Time\Duration;

/**
 * A single-process HTTP/1.1 server on the native Io\Poll event loop.
 */
final class HttpServer
{
    /** The loop wakes up at least this often, so it sees stop() and signals. */
    private const float HEARTBEAT = 0.5;

    private readonly Context $poll;

    private readonly TimerQueue $timers;

    private readonly StaticFiles $staticFiles;

    /** @var \Closure(string): void */
    private readonly \Closure $logger;

    /** @var resource|null */
    private mixed $socket = null;

    private ?Watcher $listener = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    private ?int $heartbeatTimer = null;

    private bool $stopRequested = false;

    /**
     * @param array<string, string>           $staticMounts url prefix => directory
     * @param (\Closure(string): void)|null   $logger       receives access log lines and errors
     */
    public function __construct(
        private readonly RequestHandler $handler,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        array $staticMounts = [],
        ?\Closure $logger = null,
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly float $idleTimeout = 15.0,
    ) {
        $this->poll = new Context(Backend::Auto);
        $this->timers = new TimerQueue();
        $this->staticFiles = new StaticFiles($staticMounts);
        $this->logger = $logger ?? static function (string $line): void {
            fwrite(\STDERR, $line.\PHP_EOL);
        };
    }

    /**
     * Runs the event loop until stop() is called.
     */
    public function run(): void
    {
        $this->listen();
        $this->listener = $this->poll->add(new \StreamPollHandle($this->socket), [Event::Read]);
        $this->heartbeat();

        try {
            while (!$this->stopRequested) {
                $this->tick();
            }
        } finally {
            $this->shutdown();
            $this->stopRequested = false;
        }
    }

    /**
     * Makes run() return after the current tick.
     */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * The real port, also when the constructor got port 0.
     */
    public function getPort(): int
    {
        $this->listen();
        $name = stream_socket_get_name($this->socket, false);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    /**
     * @param \Closure(): void $callback runs once, inside the event loop
     */
    public function addTimer(float $seconds, \Closure $callback): int
    {
        return $this->timers->add($seconds, $callback);
    }

    public function cancelTimer(int $id): void
    {
        $this->timers->cancel($id);
    }

    private function listen(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $address = \sprintf(str_contains($this->host, ':') ? 'tcp://[%s]:%d' : 'tcp://%s:%d', $this->host, $this->port);
        $context = stream_context_create(['socket' => ['backlog' => 511, 'so_reuseaddr' => true]]);
        $socket = @stream_socket_server($address, $errno, $error, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);

        if ($socket === false) {
            throw new \RuntimeException(\sprintf('Cannot listen on %s: %s (%d)', $address, $error, $errno));
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    private function tick(): void
    {
        $delay = $this->timers->nextDelay() ?? self::HEARTBEAT;

        try {
            $watchers = $this->poll->wait(Duration::fromMicroseconds((int) ceil($delay * 1_000_000)));
        } catch (FailedPollWaitException) {
            $watchers = []; // For example EINTR from a signal: the loop checks stop() and waits again.
        }

        foreach ($watchers as $watcher) {
            $connection = $watcher->getData();

            if ($connection instanceof Connection) {
                $this->onConnectionEvent($connection, $watcher);
            } elseif ($watcher === $this->listener) {
                $this->accept();
            }
        }

        $this->timers->runDue();
    }

    private function heartbeat(): void
    {
        $this->heartbeatTimer = $this->timers->add(self::HEARTBEAT, $this->heartbeat(...));
    }

    private function accept(): void
    {
        while (($stream = @stream_socket_accept($this->socket, 0, $peer)) !== false) {
            $connection = new Connection($stream, (string) $peer, $this->poll, $this->maxBodyBytes, $this->timers->now());
            $this->connections[spl_object_id($connection)] = $connection;
            $this->armIdleTimer($connection, $this->idleTimeout);
        }
    }

    private function onConnectionEvent(Connection $connection, Watcher $watcher): void
    {
        if ($watcher->hasTriggered(Event::Error) || $watcher->hasTriggered(Event::HangUp)) {
            $this->close($connection);

            return;
        }

        if ($watcher->hasTriggered(Event::Read)) {
            $data = $connection->read();

            if ($data !== '') {
                $connection->lastActivity = $this->timers->now();
                $connection->parser->feed($data);
                $this->process($connection);
            }

            if ($connection->eof) {
                $connection->closeAfterFlush = true;
            }
        }

        if ($connection->hasOutput()) {
            if (!$connection->flush()) {
                $this->close($connection);

                return;
            }

            $connection->lastActivity = $this->timers->now();
        }

        if ($connection->closeAfterFlush && !$connection->hasOutput()) {
            $this->close($connection);

            return;
        }

        $connection->updateEvents();
    }

    /**
     * Answers every complete request in the buffer, in order (HTTP pipelining).
     */
    private function process(Connection $connection): void
    {
        while (!$connection->closeAfterFlush) {
            try {
                $parsed = $connection->parser->next();
            } catch (HttpException $e) {
                $this->rejectRequest($connection, $e);

                return;
            }

            if ($parsed === null) {
                break;
            }

            $connection->send($this->respond($parsed->request, $parsed->keepAlive));
            $connection->closeAfterFlush = !$parsed->keepAlive || $this->stopRequested;
        }

        if (!$connection->closeAfterFlush && $connection->parser->takeContinue()) {
            $connection->send("HTTP/1.1 100 Continue\r\n\r\n");
        }
    }

    private function respond(Request $request, bool $keepAlive): string
    {
        $start = hrtime(true);

        try {
            $response = $this->staticFiles->serve($request)
                ?? $this->handler->handle($request)
                ?? Response::notFound();
        } catch (\Throwable $e) {
            ($this->logger)(\sprintf('[%s] Uncaught %s', date(\DATE_ATOM), $e));
            $response = $this->internalError($e);
        }

        $head = $request->method === 'HEAD';
        $this->logAccess($request->method, $request->path, $response, $head, $start);

        // The handler can call stop(): then this is the last response on the connection.
        return ResponseSerializer::serialize($response, $head, $keepAlive && !$this->stopRequested);
    }

    private function rejectRequest(Connection $connection, HttpException $e): void
    {
        $response = Response::text($e->getMessage()."\n", $e->status);
        $this->logAccess('-', '-', $response, false, hrtime(true));
        $connection->send(ResponseSerializer::serialize($response, false, false));
        $connection->closeAfterFlush = true;
    }

    private function internalError(\Throwable $e): Response
    {
        $body = "Internal Server Error\n";

        if (getenv('RHP_DEBUG') === '1') {
            $body .= \sprintf("\n%s: %s\n", $e::class, $e->getMessage());
        }

        return Response::text($body, 500);
    }

    private function logAccess(string $method, string $path, Response $response, bool $head, int $start): void
    {
        $bytes = ResponseSerializer::sendsBody($response->status, $head) ? \strlen($response->body) : 0;
        $ms = (hrtime(true) - $start) / 1e6;

        ($this->logger)(\sprintf('[%s] %s %s %d %d %.1fms', date(\DATE_ATOM), $method, $path, $response->status, $bytes, $ms));
    }

    /**
     * Closes the connection after idleTimeout seconds without traffic.
     * One timer per connection: it moves itself forward when there was traffic.
     */
    private function armIdleTimer(Connection $connection, float $delay): void
    {
        $connection->idleTimer = $this->timers->add($delay, function () use ($connection): void {
            $remaining = $connection->lastActivity + $this->idleTimeout - $this->timers->now();

            if ($remaining > 0) {
                $this->armIdleTimer($connection, $remaining);
            } else {
                $this->close($connection);
            }
        });
    }

    private function close(Connection $connection): void
    {
        if ($connection->idleTimer !== null) {
            $this->timers->cancel($connection->idleTimer);
            $connection->idleTimer = null;
        }

        $connection->close();
        unset($this->connections[spl_object_id($connection)]);
    }

    private function shutdown(): void
    {
        if ($this->heartbeatTimer !== null) {
            $this->timers->cancel($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        foreach ($this->connections as $connection) {
            $connection->flush(); // Best effort, for example the response of the request that called stop().
            $this->close($connection);
        }

        if ($this->listener?->isActive()) {
            $this->listener->remove();
        }

        $this->listener = null;

        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}
