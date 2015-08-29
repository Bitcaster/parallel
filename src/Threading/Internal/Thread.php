<?php
namespace Icicle\Concurrent\Threading\Internal;

use Icicle\Concurrent\Sync\Channel;
use Icicle\Concurrent\Sync\ChannelInterface;
use Icicle\Concurrent\Sync\Internal\ExitFailure;
use Icicle\Concurrent\Sync\Internal\ExitSuccess;
use Icicle\Concurrent\Threading\Executor;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Socket\Stream\DuplexStream;

/**
 * An internal thread that executes a given function concurrently.
 *
 * @internal
 */
class Thread extends \Thread
{
    /**
     * @var callable The function to execute in the thread.
     */
    private $function;

    /**
     * @var
     */
    private $args;

    /**
     * @var resource
     */
    private $socket;

    /**
     * @var bool
     */
    private $lock = true;

    /**
     * Creates a new thread object.
     *
     * @param resource $socket   IPC communication socket.
     * @param callable $function The function to execute in the thread.
     * @param mixed[]  $args     Arguments to pass to the function.
     */
    public function __construct($socket, callable $function, array $args = [])
    {
        $this->function = $function;
        $this->args = $args;
        $this->socket = $socket;
    }

    /**
     * Runs the thread code and the initialized function.
     */
    public function run()
    {
        /* First thing we need to do is re-initialize the class autoloader. If
         * we don't do this first, any object of a class that was loaded after
         * the thread started will just be garbage data and unserializable
         * values (like resources) will be lost. This happens even with
         * thread-safe objects.
         */
        foreach (get_declared_classes() as $className) {
            if (strpos($className, 'ComposerAutoloaderInit') === 0) {
                // Calling getLoader() will register the class loader for us
                $className::getLoader();
                break;
            }
        }

        // At this point, the thread environment has been prepared so begin using the thread.
        $channel = new Channel(new DuplexStream($this->socket));

        $coroutine = new Coroutine($this->execute($channel));
        $coroutine->done();

        Loop\run();
    }

    /**
     * Attempts to obtain the lock. Returns true if the lock was obtained.
     *
     * @return bool
     */
    public function tsl()
    {
        if (!$this->lock) {
            return false;
        }

        return $this->synchronized(function () {
            if ($this->lock) {
                $this->lock = false;
                return true;
            }
            return false;
        });
    }

    /**
     * Releases the lock.
     */
    public function release()
    {
        $this->lock = true;
    }

    /**
     * @coroutine
     *
     * @param \Icicle\Concurrent\Sync\ChannelInterface $channel
     *
     * @return \Generator
     *
     * @resolve int
     */
    private function execute(ChannelInterface $channel)
    {
        $executor = new Executor($this, $channel);

        try {
            if ($this->function instanceof \Closure) {
                $function = $this->function->bindTo($executor, Executor::class);
            }

            if (empty($function)) {
                $function = $this->function;
            }

            $result = new ExitSuccess(yield call_user_func_array($function, $this->args));
        } catch (\Exception $exception) {
            $result = new ExitFailure($exception);
        }

        yield $channel->send($result);
    }
}
