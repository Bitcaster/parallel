<?php

namespace Amp\Concurrent\Worker;

use Amp\Concurrent\{ StatusError, Strand, WorkerException} ;
use Amp\Concurrent\Worker\Internal\{ Job, TaskResult };
use Amp\{ Coroutine, Deferred };
use Interop\Async\Awaitable;

/**
 * Base class for most common types of task workers.
 */
abstract class AbstractWorker implements Worker {
    /** @var \Amp\Concurrent\Strand */
    private $context;

    /** @var bool */
    private $shutdown = false;
    
    /** @var \Amp\Deferred[] */
    private $jobQueue = [];
    
    /** @var callable */
    private $when;

    /**
     * @param \Amp\Concurrent\Strand $strand
     */
    public function __construct(Strand $strand) {
        $this->context = $strand;
        
        $this->when = function ($exception, $data) {
            if ($exception) {
                $this->kill();
                return;
            }
    
            if (!$data instanceof TaskResult) {
                $this->kill();
                return;
            }
    
            $id = $data->getId();
    
            if (!isset($this->jobQueue[$id])) {
                $this->kill();
                return;
            }
    
            $deferred = $this->jobQueue[$id];
            unset($this->jobQueue[$id]);
            
            if (!empty($this->jobQueue)) {
                $this->context->receive()->when($this->when);
            }
            
            $deferred->resolve($data->getAwaitable());
        };
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool {
        return $this->context->isRunning();
    }

    /**
     * {@inheritdoc}
     */
    public function isIdle(): bool {
        return empty($this->jobQueue);
    }

    /**
     * {@inheritdoc}
     */
    public function start() {
        $this->context->start();
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue(Task $task): Awaitable {
        if (!$this->context->isRunning()) {
            throw new StatusError('The worker has not been started.');
        }
    
        if ($this->shutdown) {
            throw new StatusError('The worker has been shut down.');
        }
    
        return new Coroutine($this->doEnqueue($task));
    }

    /**
     * @coroutine
     *
     * @param \Amp\Concurrent\Worker\Task $task
     *
     * @return \Generator
     * @throws \Amp\Concurrent\StatusError
     * @throws \Amp\Concurrent\TaskException
     * @throws \Amp\Concurrent\WorkerException
     */
    private function doEnqueue(Task $task): \Generator {
        if (empty($this->jobQueue)) {
            $this->context->receive()->when($this->when);
        }
        
        try {
            $job = new Job($task);
            $this->jobQueue[$job->getId()] = $deferred = new Deferred;
            yield $this->context->send($job);
        } catch (\Throwable $exception) {
            $this->kill();
            throw new WorkerException('Sending the task to the worker failed.', $exception);
        }
    
        return yield $deferred->getAwaitable();
    }

    /**
     * {@inheritdoc}
     */
    public function shutdown(): Awaitable {
        if (!$this->context->isRunning() || $this->shutdown) {
            throw new StatusError('The worker is not running.');
        }
    
        return new Coroutine($this->doShutdown());
    }

    /**
     * {@inheritdoc}
     */
    private function doShutdown(): \Generator {
        $this->shutdown = true;

        // If a task is currently running, wait for it to finish.
        yield \Amp\any($this->jobQueue);

        yield $this->context->send(0);
        return yield $this->context->join();
    }

    /**
     * {@inheritdoc}
     */
    public function kill() {
        $this->cancelPending();
        $this->context->kill();
    }

    /**
     * Cancels all pending tasks.
     */
    private function cancelPending() {
        if (!empty($this->jobQueue)) {
            $exception = new WorkerException('Worker was shut down.');
            
            foreach ($this->jobQueue as $job) {
                $job->fail($exception);
            }
            
            $this->jobQueue = [];
        }
    }
}
