<?php

namespace Amp\Parallel\Test\Worker;

use Amp\Parallel\Worker\BootstrapWorkerFactory;
use Amp\PHPUnit\AsyncTestCase;

class BootstrapWorkerFactoryTest extends AsyncTestCase
{
    public function testAutoloading()
    {
        $factory = new BootstrapWorkerFactory(__DIR__ . '/Fixtures/custom-bootstrap.php');

        $worker = $factory->create();

        $this->assertTrue(yield $worker->enqueue(new Fixtures\AutoloadTestTask));

        yield $worker->shutdown();
    }

    public function testInvalidAutoloaderPath()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('No file found at autoload path given');

        $factory = new BootstrapWorkerFactory(__DIR__ . '/Fixtures/not-found.php');
    }

    public function testInvalidClassName()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid environment class name 'Invalid'");

        $factory = new BootstrapWorkerFactory(__DIR__ . '/Fixtures/custom-bootstrap.php', "Invalid");
    }

    public function testNonEnvironmentClassName()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("does not implement 'Amp\Parallel\Worker\Environment'");

        $factory = new BootstrapWorkerFactory(
            __DIR__ . '/Fixtures/custom-bootstrap.php',
            BootstrapWorkerFactory::class
        );
    }
}
