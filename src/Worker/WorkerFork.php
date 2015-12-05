<?php
namespace Icicle\Concurrent\Worker;

use Icicle\Concurrent\Forking\Fork;
use Icicle\Concurrent\Worker\Internal\TaskRunner;

/**
 * A worker thread that executes task objects.
 */
class WorkerFork extends AbstractWorker
{
    public function __construct()
    {
        parent::__construct(new Fork(function () {
            $runner = new TaskRunner($this, new Environment());
            yield $runner->run();
        }));
    }
}
