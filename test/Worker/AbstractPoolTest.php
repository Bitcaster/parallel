<?php

namespace Amp\Parallel\Test\Worker;

use Amp\Parallel\Test\TestCase;
use Interop\Async\Loop;

abstract class AbstractPoolTest extends TestCase {
    /**
     * @param int $min
     * @param int $max
     *
     * @return \Amp\Parallel\Worker\Pool
     */
    abstract protected function createPool($min = null, $max = null);

    public function testIsRunning() {
        Loop::execute(\Amp\wrap(function () {
            $pool = $this->createPool();
            $this->assertFalse($pool->isRunning());

            $pool->start();
            $this->assertTrue($pool->isRunning());

            yield $pool->shutdown();
            $this->assertFalse($pool->isRunning());
        }));
    }

    public function testIsIdleOnStart() {
        Loop::execute(\Amp\wrap(function () {
            $pool = $this->createPool();
            $pool->start();

            $this->assertTrue($pool->isIdle());

            yield $pool->shutdown();
        }));
    }

    public function testGetMinSize() {
        $pool = $this->createPool(7, 24);
        $this->assertEquals(7, $pool->getMinSize());
    }

    public function testGetMaxSize() {
        $pool = $this->createPool(3, 17);
        $this->assertEquals(17, $pool->getMaxSize());
    }

    public function testMinWorkersSpawnedOnStart() {
        Loop::execute(\Amp\wrap(function () {
            $pool = $this->createPool(8, 32);
            $pool->start();

            $this->assertEquals(8, $pool->getWorkerCount());

            yield $pool->shutdown();
        }));
    }

    public function testWorkersIdleOnStart() {
        Loop::execute(\Amp\wrap(function () {
            $pool = $this->createPool(8, 32);
            $pool->start();

            $this->assertEquals(8, $pool->getIdleWorkerCount());

            yield $pool->shutdown();
        }));
    }

    public function testEnqueue() {
        Loop::execute(\Amp\wrap(function () {
            $pool = $this->createPool();
            $pool->start();

            $returnValue = yield $pool->enqueue(new TestTask(42));
            $this->assertEquals(42, $returnValue);

            yield $pool->shutdown();
        }));
    }

    public function testEnqueueMultiple() {
        Loop::execute(\Amp\wrap(function () {
            $pool = $this->createPool();
            $pool->start();

            $values = yield \Amp\all([
                $pool->enqueue(new TestTask(42)),
                $pool->enqueue(new TestTask(56)),
                $pool->enqueue(new TestTask(72))
            ]);

            $this->assertEquals([42, 56, 72], $values);

            yield $pool->shutdown();
        }));
    }

    public function testKill() {
        $pool = $this->createPool();
        $pool->start();

        $this->assertRunTimeLessThan([$pool, 'kill'], 1);
        $this->assertFalse($pool->isRunning());
    }
}
