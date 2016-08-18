<?php

namespace Amp\Concurrent\Threading\Internal;

use Amp\Concurrent\{ChannelException, SerializationException};
use Amp\Concurrent\Sync\{Channel, ChannelledStream, Internal\ExitFailure, Internal\ExitSuccess};
use Amp\Coroutine;
use Amp\Socket\Socket;
use Interop\Async\Awaitable;

/**
 * An internal thread that executes a given function concurrently.
 *
 * @internal
 */
class Thread extends \Thread {
    const KILL_CHECK_FREQUENCY = 250;

    /**
     * @var callable The function to execute in the thread.
     */
    private $function;

    /**
     * @var mixed[] Arguments to pass to the function.
     */
    private $args;

    /**
     * @var resource
     */
    private $socket;

    /**
     * @var bool
     */
    private $killed = false;

    /**
     * Creates a new thread object.
     *
     * @param resource $socket   IPC communication socket.
     * @param callable $function The function to execute in the thread.
     * @param mixed[]  $args     Arguments to pass to the function.
     */
    public function __construct($socket, callable $function, array $args = []) {
        $this->function = $function;
        $this->args = $args;
        $this->socket = $socket;
    }

    /**
     * Runs the thread code and the initialized function.
     *
     * @codeCoverageIgnore Only executed in thread.
     */
    public function run() {
        /* First thing we need to do is re-initialize the class autoloader. If
         * we don't do this first, any object of a class that was loaded after
         * the thread started will just be garbage data and unserializable
         * values (like resources) will be lost. This happens even with
         * thread-safe objects.
         */
        $paths = [
            \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR . 'autoload.php',
            \dirname(__DIR__, 3) . \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR . 'autoload.php',
        ];
    
        $autoloadPath = null;
        foreach ($paths as $path) {
            if (\file_exists($path)) {
                $autoloadPath = $path;
                break;
            }
        }
    
        if (null === $autoloadPath) {
            echo 'Could not locate autoload.php.';
            exit(1);
        }
    
        require $autoloadPath;
        
        // At this point, the thread environment has been prepared so begin using the thread.

        try {
            \Amp\execute(function () {
                try {
                    $channel = new ChannelledStream(new Socket($this->socket, false));
                } catch (\Throwable $exception) {
                    return 1; // Parent has destroyed Thread object, so just exit.
                }
        
                $watcher = \Amp\repeat(self::KILL_CHECK_FREQUENCY, function () {
                    if ($this->killed) {
                        \Amp\stop();
                    }
                });
        
                \Amp\unreference($watcher);
        
                return new Coroutine($this->execute($channel));
            });
        } catch (\Throwable $exception) {
            return 1;
        }
        
        return 0;
    }

    /**
     * Sets a local variable to true so the running event loop can check for a kill signal.
     */
    public function kill() {
        return $this->killed = true;
    }

    /**
     * @coroutine
     *
     * @param \Amp\Concurrent\Sync\Channel $channel
     *
     * @return \Generator
     *
     * @codeCoverageIgnore Only executed in thread.
     */
    private function execute(Channel $channel): \Generator {
        try {
            if ($this->function instanceof \Closure) {
                $function = $this->function->bindTo($channel, Channel::class);
            }

            if (empty($function)) {
                $function = $this->function;
            }
    
            $result = $function(...$this->args);
            
            if ($result instanceof \Generator) {
                $result = new Coroutine($result);
            }
            
            if ($result instanceof Awaitable) {
                $result = yield $result;
            }

            $result = new ExitSuccess($result);
        } catch (\Throwable $exception) {
            $result = new ExitFailure($exception);
        }

        // Attempt to return the result.
        try {
            try {
                return yield $channel->send($result);
            } catch (SerializationException $exception) {
                // Serializing the result failed. Send the reason why.
                return yield $channel->send(new ExitFailure($exception));
            }
        } catch (ChannelException $exception) {
            // The result was not sendable! The parent context must have died or killed the context.
            return 0;
        }
    }
}
