<?php

namespace Amp\Tests\Concurrent\Worker;

use Amp\Concurrent\Worker\{DefaultPool, WorkerFactory, WorkerThread};

/**
 * @group threading
 * @requires extension pthreads
 */
class ThreadPoolTest extends AbstractPoolTest
{
    protected function createPool($min = null, $max = null)
    {
        $factory = $this->getMock(WorkerFactory::class);
        $factory->method('create')->will($this->returnCallback(function () {
            return new WorkerThread();
        }));

        return new DefaultPool($min, $max, $factory);
    }
}
