<?php

namespace Amp\Parallel\Context;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Socket\ResourceSocket;
use Amp\TimeoutCancellation;
use Amp\Socket;
use Amp\Socket\Socket as StreamSocket;
use Revolt\EventLoop;
use const Amp\Process\IS_WINDOWS;

final class IpcHub
{
    public const KEY_RECEIVE_TIMEOUT = 1;
    public const KEY_LENGTH = 64;

    private int $nextId = 0;

    private Socket\ResourceSocketServer $server;

    private string $uri;

    /** @var int[] */
    private array $keys = [];

    /** @var DeferredFuture[] */
    private array $acceptor = [];

    private \Closure $accept;

    private ?string $toUnlink = null;

    public function __construct()
    {
        if (IS_WINDOWS) {
            $this->uri = "tcp://127.0.0.1:0";
        } else {
            $suffix = \bin2hex(\random_bytes(10));
            $path = \sys_get_temp_dir() . "/amp-parallel-ipc-" . $suffix . ".sock";
            $this->uri = "unix://" . $path;
            $this->toUnlink = $path;
        }

        $this->server = $server = Socket\listen($this->uri);

        if (IS_WINDOWS) {
            $this->uri = "tcp://127.0.0.1:" . $this->server->getAddress()->getPort();
        }

        $keys = &$this->keys;
        $acceptor = &$this->acceptor;
        $this->accept = static function () use (&$keys, &$acceptor, $server): void {
            while (!empty($acceptor) && $client = $server->accept()) {
                try {
                    $received = self::readKey($client, new TimeoutCancellation(self::KEY_RECEIVE_TIMEOUT));
                } catch (\Throwable) {
                    $client->close();
                    continue; // Ignore possible foreign connection attempt.
                }

                $id = $keys[$received] ?? null;

                if ($id === null) {
                    $client->close();
                    continue; // Ignore possible foreign connection attempt.
                }

                $deferred = $acceptor[$id] ?? null;

                if ($deferred === null) {
                    $client->close();
                    continue; // Client accept cancelled.
                }

                unset($acceptor[$id], $keys[$received]);
                $deferred->complete($client);
            }
        };
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isClosed(): bool
    {
        return $this->server->isClosed();
    }

    public function close(): void
    {
        $this->server->close();
        if ($this->toUnlink !== null) {
            @\unlink($this->toUnlink);
        }
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function generateKey(): string
    {
        return \random_bytes(self::KEY_LENGTH);
    }

    /**
     * @param Cancellation|null $cancellation
     *
     * @return StreamSocket
     * @throws ContextException
     */
    public function accept(string $key, ?Cancellation $cancellation = null): ResourceSocket
    {
        $id = $this->nextId++;

        if (empty($this->acceptor)) {
            EventLoop::queue($this->accept);
        }

        $this->keys[$key] = $id;
        $this->acceptor[$id] = $deferred = new DeferredFuture;

        try {
            $client = $deferred->getFuture()->await($cancellation);
        } catch (CancelledException $exception) {
            unset($this->acceptor[$id], $this->keys[$key]);
            throw new ContextException("Starting the process timed out", 0, $exception);
        }

        return $client;
    }

    /**
     * Note this is designed to be used in the child process/thread.
     *
     * @param ReadableResourceStream|ResourceSocket $stream
     * @param Cancellation|null $cancellation Closes the stream if cancelled.
     */
    public static function readKey(
        ReadableResourceStream|ResourceSocket $stream,
        ?Cancellation $cancellation = null
    ): string {
        $key = "";

        // Read random key from $stream and send back to parent over IPC socket to authenticate.
        do {
            if (($chunk = $stream->read($cancellation, self::KEY_LENGTH - \strlen($key))) === null) {
                throw new \RuntimeException("Could not read key from parent", E_USER_ERROR);
            }
            $key .= $chunk;
        } while (\strlen($key) < self::KEY_LENGTH);

        return $key;
    }

    /**
     * Note that this is designed to be used in the child process/thread and performs a blocking connect.
     *
     * @return StreamSocket
     */
    public static function connect(
        string $uri,
        string $key,
        ?Cancellation $cancellation = null,
        ?Socket\Connector $connector = null,
    ): StreamSocket {
        $connector ??= Socket\connector();

        $client = $connector->connect($uri, cancellation: $cancellation);
        $client->write($key);

        return $client;
    }
}