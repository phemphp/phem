<?php

declare(strict_types=1);

namespace Rephp\Server;

use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\Watcher;

/**
 * One client socket: its watcher, its parser and its output buffer.
 */
final class Connection
{
    /** Maximum bytes to read in one tick, so one client cannot hold the loop. */
    private const int READ_LIMIT = 1_048_576;

    public readonly HttpParser $parser;

    public private(set) Watcher $watcher;

    public private(set) string $output = '';

    /** The peer closed its side; no more requests will come. */
    public private(set) bool $eof = false;

    public private(set) bool $closed = false;

    /** Close the connection when the output buffer is empty. */
    public bool $closeAfterFlush = false;

    public float $lastActivity;

    public ?int $idleTimer = null;

    /** @var list<Event> */
    private array $events = [Event::Read];

    /**
     * @param resource $stream a non-blocking socket
     */
    public function __construct(
        private readonly mixed $stream,
        public readonly string $peer,
        Context $poll,
        int $maxBodyBytes,
        float $now,
    ) {
        stream_set_blocking($stream, false);
        stream_set_read_buffer($stream, 0);

        $this->parser = new HttpParser($maxBodyBytes);
        $this->lastActivity = $now;
        $this->watcher = $poll->add(new \StreamPollHandle($stream), $this->events, $this);
    }

    /**
     * Reads what the socket has now. It sets $eof when the peer closed its side.
     */
    public function read(): string
    {
        $data = '';

        while (\strlen($data) < self::READ_LIMIT) {
            $chunk = @fread($this->stream, 65_536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        if ($data === '' && feof($this->stream)) {
            $this->eof = true;
        }

        return $data;
    }

    public function send(string $bytes): void
    {
        $this->output .= $bytes;
    }

    public function hasOutput(): bool
    {
        return $this->output !== '';
    }

    /**
     * Writes as much of the output buffer as the socket accepts.
     *
     * @return bool false when the socket failed
     */
    public function flush(): bool
    {
        while ($this->output !== '') {
            $written = @fwrite($this->stream, $this->output);

            if ($written === false) {
                return false;
            }

            if ($written === 0) {
                break; // The kernel buffer is full: wait for Event::Write.
            }

            $this->output = substr($this->output, $written);
        }

        return true;
    }

    /**
     * Watches Read while requests can come, and Write while output waits.
     */
    public function updateEvents(): void
    {
        $events = [];

        if (!$this->eof && !$this->closeAfterFlush) {
            $events[] = Event::Read;
        }

        if ($this->output !== '') {
            $events[] = Event::Write;
        }

        if ($events !== $this->events && $this->watcher->isActive()) {
            $this->watcher->modifyEvents($events);
            $this->events = $events;
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->watcher->isActive()) {
            $this->watcher->remove();
        }

        @fclose($this->stream);
    }
}
