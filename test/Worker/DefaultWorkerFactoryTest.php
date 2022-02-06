<?php

namespace Amp\Parallel\Test\Worker;

use Amp\Cache\Cache;
use Amp\Parallel\Worker\DefaultWorkerFactory;
use Amp\Parallel\Worker\Worker;
use Amp\PHPUnit\AsyncTestCase;

class DefaultWorkerFactoryTest extends AsyncTestCase
{
    public function testInvalidClassName(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid cache class name 'Invalid'");

        new DefaultWorkerFactory(cacheClass: "Invalid");
    }

    public function testNonCacheClassName(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage(\sprintf("does not implement '%s'", Cache::class));

        new DefaultWorkerFactory(cacheClass: DefaultWorkerFactory::class);
    }

    public function testCreate(): void
    {
        $factory = new DefaultWorkerFactory;

        self::assertInstanceOf(Worker::class, $worker = $factory->create());

        $worker->shutdown();
    }

    public function testAutoloading(): void
    {
        $factory = new DefaultWorkerFactory(__DIR__ . '/Fixtures/custom-bootstrap.php');

        $worker = $factory->create();

        self::assertTrue($worker->submit(new Fixtures\AutoloadTestTask)->getResult()->await());

        $worker->shutdown();
    }

    public function testInvalidAutoloaderPath(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('No file found at bootstrap path given');

        $factory = new DefaultWorkerFactory(__DIR__ . '/Fixtures/not-found.php');

        $worker = $factory->create();

        $worker->submit(new Fixtures\TestTask(42))->getResult()->await();
    }
}
