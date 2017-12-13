<?php

namespace Amp\Parallel\Test\Worker;

use Amp\Loop;
use Amp\Parallel\Worker\Pool;
use Amp\PHPUnit\TestCase;

abstract class AbstractPoolTest extends TestCase {
    /**
     * @param int $min
     * @param int $max
     *
     * @return \Amp\Parallel\Worker\Pool
     */
    abstract protected function createPool($max = Pool::DEFAULT_MAX_SIZE): Pool;

    public function testIsRunning() {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertTrue($pool->isRunning());

            yield $pool->shutdown();
            $this->assertFalse($pool->isRunning());
        });
    }

    public function testIsIdleOnStart() {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertTrue($pool->isIdle());

            yield $pool->shutdown();
        });
    }

    public function testGetMaxSize() {
        $pool = $this->createPool(17);
        $this->assertEquals(17, $pool->getMaxSize());
    }

    public function testWorkersIdleOnStart() {
        Loop::run(function () {
            $pool = $this->createPool(32);

            $this->assertEquals(0, $pool->getIdleWorkerCount());

            yield $pool->shutdown();
        });
    }

    public function testEnqueue() {
        Loop::run(function () {
            $pool = $this->createPool();

            $returnValue = yield $pool->enqueue(new TestTask(42));
            $this->assertEquals(42, $returnValue);

            yield $pool->shutdown();
        });
    }

    public function testEnqueueMultiple() {
        Loop::run(function () {
            $pool = $this->createPool();

            $values = yield \Amp\Promise\all([
                $pool->enqueue(new TestTask(42)),
                $pool->enqueue(new TestTask(56)),
                $pool->enqueue(new TestTask(72))
            ]);

            $this->assertEquals([42, 56, 72], $values);

            yield $pool->shutdown();
        });
    }

    public function testKill() {
        $pool = $this->createPool();

        $this->assertRunTimeLessThan([$pool, 'kill'], 1000);
        $this->assertFalse($pool->isRunning());
    }
}
