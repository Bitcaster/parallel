<?php
namespace Icicle\Concurrent\Threading;

use Icicle\Concurrent\Sync\Channel;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;

/**
 * An internal thread that executes a given function concurrently.
 */
class Thread extends \Thread
{
    /**
     * @var ThreadContext An instance of the context local to this thread.
     */
    public $context;

    /**
     * @var string|null Path to an autoloader to include.
     */
    public $autoloaderPath;

    /**
     * @var callable The function to execute in the thread.
     */
    private $function;

    public $prepared = false;
    public $initialized = false;

    private $channel;
    private $socket;

    /**
     * Creates a new thread object.
     *
     * @param callable $function The function to execute in the thread.
     */
    public function __construct(callable $function)
    {
        $this->context = ThreadContext::createLocalInstance($this);
        $this->function = $function;
    }

    /**
     * Initializes the thread by injecting values from the parent into threaded memory.
     *
     * @param resource $socket The channel socket to communicate to the parent with.
     */
    public function init($socket)
    {
        $this->socket = $socket;
        $this->initialized = true;
    }

    /**
     * Runs the thread code and the initialized function.
     */
    public function run()
    {
        // First thing we need to do is prepare the thread environment to make
        // it usable, so lock the thread while we do it. Hopefully we get the
        // lock first, but if we don't the parent will release and give us a
        // chance before continuing.
        $this->lock();

        // First thing we need to do is initialize the class autoloader. If we
        // don't do this first, objects we receive from other threads will just
        // be garbage data and unserializable values (like resources) will be
        // lost. This happens even with thread-safe objects.
        if (file_exists($this->autoloaderPath)) {
            require $this->autoloaderPath;
        }

        // Initialize the thread-local global event loop.
        Loop\loop();

        // Now let the parent thread know that we are done preparing the
        // thread environment and are ready to accept data.
        $this->prepared = true;
        $this->notify();
        $this->unlock();

        // Wait for objects to be injected by the context wrapper object.
        $this->lock();
        if (!$this->initialized) {
            $this->wait();
        }
        $this->unlock();

        // At this point, the thread environment has been prepared, and the
        // parent has finished injecting values into our memory.

        $this->channel = new LocalObject(new Channel($this->socket));
        //$this->socket = null;

        //register_shutdown_function([$this, 'handleShutdown']);
        try {
            if ($this->function instanceof \Closure) {
                $generator = $this->function->bindTo($this->context)->__invoke();
            } else {
                $generator = call_user_func($this->function);
            }

            if ($generator instanceof \Generator) {
                $coroutine = new Coroutine($generator);
            } else {
                $returnValue = $generator;
            }
        } catch (\Exception $exception) {
            print $exception;

            $panic = [
                'panic' => [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'trace' => array_map([$this, 'removeTraceArgs'], $exception->getTrace()),
                ],
            ];

            $this->channel->deref()->send($panic);
        } finally {
            $this->channel->deref()->close();
        }

        Loop\run();
        $this->channel->free();
    }

    public function handleShutdown()
    {
        if ($error = error_get_last()) {
            $panic = [
                'message' => $error['message'],
                'code' => 0,
                'trace' => array_map([$this, 'removeTraceArgs'], debug_backtrace()),
            ];

            $this->sendMessage(self::MSG_ERROR);
            $serialized = serialize($panic);
            $length = strlen($serialized);
            fwrite($this->socket, pack('S', $length).$serialized);
            fclose($this->socket);
        }
    }

    private function sendMessage($message)
    {
        fwrite($this->socket, chr($message));
    }

    public function removeTraceArgs($trace)
    {
        unset($trace['args']);
        return $trace;
    }
}
