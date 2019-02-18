<?php

namespace Amp\Parallel\Context;

use Amp\Failure;
use Amp\Loop;
use Amp\Parallel\Sync\ChannelException;
use Amp\Parallel\Sync\ChannelledSocket;
use Amp\Parallel\Sync\ExitResult;
use Amp\Parallel\Sync\SerializationException;
use Amp\Parallel\Sync\SynchronizationError;
use Amp\Promise;
use parallel\Runtime;
use function Amp\call;

/**
 * Implements an execution context using native multi-threading.
 *
 * The thread context is not itself threaded. A local instance of the context is
 * maintained both in the context that creates the thread and in the thread
 * itself.
 */
final class Parallel implements Context
{
    const KEY_LENGTH = 32;

    /** @var string|null */
    private static $autoloadPath;

    /** @var int Next ID to be used for IPC hub. */
    private static $id = 1;

    /** @var Internal\ProcessHub */
    private $hub;

    /** @var Runtime|null */
    private $runtime;

    /** @var ChannelledSocket|null A channel for communicating with the parallel thread. */
    private $channel;

    /** @var string Script path. */
    private $script;

    /** @var mixed[] */
    private $args = [];

    /** @var int */
    private $oid = 0;

    /** @var bool */
    private $killed = false;

    /** @var \parallel\Future|null */
    private $future;

    /**
     * Checks if threading is enabled.
     *
     * @return bool True if threading is enabled, otherwise false.
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('parallel');
    }

    /**
     * Creates and starts a new thread.
     *
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     * @param mixed ...$args Additional arguments to pass to the given callable.
     *
     * @return Promise<Thread> The thread object that was spawned.
     */
    public static function run($script): Promise
    {
        $thread = new self($script);
        return call(function () use ($thread) {
            yield $thread->start();
            return $thread;
        });
    }

    /**
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     *
     * @throws \Error Thrown if the pthreads extension is not available.
     */
    public function __construct($script)
    {
        $this->hub = Loop::getState(self::class);
        if (!$this->hub instanceof Internal\ProcessHub) {
            $this->hub = new Internal\ProcessHub;
            Loop::setState(self::class, $this->hub);
        }

        if (!self::isSupported()) {
            throw new \Error("The parallel extension is required to create parallel threads.");
        }

        if (\is_array($script)) {
            $this->script = (string) \array_shift($script);
            $this->args = \array_map("strval", $script);
        } else {
            $this->script = (string) $script;
        }

        if (self::$autoloadPath === null) {
            $paths = [
                \dirname(__DIR__, 2) . \DIRECTORY_SEPARATOR . "vendor" . \DIRECTORY_SEPARATOR . "autoload.php",
                \dirname(__DIR__, 4) . \DIRECTORY_SEPARATOR . "autoload.php",
            ];

            foreach ($paths as $path) {
                if (\file_exists($path)) {
                    self::$autoloadPath = $path;
                    break;
                }
            }

            if (self::$autoloadPath === null) {
                throw new \Error("Could not locate autoload.php");
            }
        }
    }

    /**
     * Returns the thread to the condition before starting. The new thread can be started and run independently of the
     * first thread.
     */
    public function __clone()
    {
        $this->runtime = null;
        $this->future = null;
        $this->channel = null;
        $this->oid = 0;
        $this->killed = false;
    }

    /**
     * Kills the thread if it is still running.
     *
     * @throws \Amp\Parallel\Context\ContextException
     */
    public function __destruct()
    {
        if (\getmypid() === $this->oid) {
            $this->kill();
        }
    }

    /**
     * Checks if the context is running.
     *
     * @return bool True if the context is running, otherwise false.
     */
    public function isRunning(): bool
    {
        return $this->channel !== null;
    }

    /**
     * Spawns the thread and begins the thread's execution.
     *
     * @return Promise<null> Resolved once the thread has started.
     *
     * @throws \Amp\Parallel\Context\StatusError If the thread has already been started.
     * @throws \Amp\Parallel\Context\ContextException If starting the thread was unsuccessful.
     */
    public function start(): Promise
    {
        if ($this->oid !== 0) {
            throw new StatusError('The thread has already been started.');
        }

        $this->oid = \getmypid();

        $this->runtime = new Runtime(self::$autoloadPath);

        $id = self::$id++;

        $this->future = $this->runtime->run(static function (string $uri, string $key, string $path, array $argv): int {
            \define("AMP_CONTEXT", "parallel");

            if (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
                \trigger_error("Could not connect to IPC socket", E_USER_ERROR);
                return 1;
            }

            $communicationChannel = new ChannelledSocket($socket, $socket);

            try {
                Promise\wait($communicationChannel->send($key));
            } catch (\Throwable $exception) {
                \trigger_error("Could not send key to parent", E_USER_ERROR);
                return 1;
            }

            return Internal\ParallelRunner::run($communicationChannel, $path, $argv);
        }, [
            $this->hub->getUri(),
            $this->hub->generateKey($id, self::KEY_LENGTH),
            $this->script,
            $this->args
        ]);

        return call(function () use ($id) {
            try {
                $this->channel = yield $this->hub->accept($id);
            } catch (\Throwable $exception) {
                $this->kill();
                throw new ContextException("Starting the parallel runtime failed", 0, $exception);
            }

            if ($this->killed) {
                $this->kill();
            }
        });
    }

    /**
     * Immediately kills the context.
     */
    public function kill()
    {
        $this->killed = true;

        if ($this->runtime !== null) {
            try {
                $this->runtime->kill();
            } finally {
                $this->close();
            }
        }
    }

    /**
     * Closes channel and socket if still open.
     */
    private function close()
    {
        $this->runtime = null;

        if ($this->channel !== null) {
            $this->channel->close();
        }

        $this->channel = null;
    }

    /**
     * Gets a promise that resolves when the context ends and joins with the
     * parent context.
     *
     * @return \Amp\Promise<mixed>
     *
     * @throws StatusError Thrown if the context has not been started.
     * @throws SynchronizationError Thrown if an exit status object is not received.
     * @throws ContextException If the context stops responding.
     */
    public function join(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError('The thread has not been started or has already finished.');
        }

        return call(function () {
            try {
                $response = yield $this->channel->receive();

                if (!$response instanceof ExitResult) {
                    throw new SynchronizationError('Did not receive an exit result from thread.');
                }
            } catch (\Throwable $exception) {
                $this->kill();
                throw new ContextException("Failed to receive result from thread", 0, $exception);
            } finally {
                $this->close();
            }

            return $response->getResult();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError('The process has not been started.');
        }

        return call(function () {
            $data = yield $this->channel->receive();

            if ($data instanceof ExitResult) {
                $data = $data->getResult();
                throw new SynchronizationError(\sprintf(
                    'Thread process unexpectedly exited with result of type: %s',
                    \is_object($data) ? \get_class($data) : \gettype($data)
                ));
            }

            return $data;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function send($data): Promise
    {
        if ($this->channel === null) {
            throw new StatusError('The thread has not been started or has already finished.');
        }

        if ($data instanceof ExitResult) {
            throw new \Error('Cannot send exit result objects.');
        }

        return $this->channel->send($data);
    }
}
