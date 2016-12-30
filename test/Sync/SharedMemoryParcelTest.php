<?php

namespace Amp\Parallel\Test\Sync;

use Amp\Parallel\Sync\SharedMemoryParcel;

/**
 * @requires extension shmop
 * @requires extension sysvmsg
 */
class SharedMemoryParcelTest extends AbstractParcelTest {
    private $parcel;

    protected function createParcel($value) {
        $this->parcel = new SharedMemoryParcel($value);
        return $this->parcel;
    }

    public function tearDown() {
        if ($this->parcel !== null) {
            $this->parcel->free();
        }
    }

    public function testNewObjectIsNotFreed() {
        $object = new SharedMemoryParcel(new \stdClass());
        $this->assertFalse($object->isFreed());
        $object->free();
    }

    public function testFreeReleasesObject() {
        $object = new SharedMemoryParcel(new \stdClass());
        $object->free();
        $this->assertTrue($object->isFreed());
    }

    /**
     * @expectedException \Amp\Parallel\SharedMemoryException
     */
    public function testUnwrapThrowsErrorIfFreed() {
        $object = new SharedMemoryParcel(new \stdClass());
        $object->free();
        $object->unwrap();
    }

    public function testCloneIsNewObject() {
        $object = new \stdClass;
        $shared = new SharedMemoryParcel($object);
        $clone = clone $shared;

        $this->assertNotSame($shared, $clone);
        $this->assertNotSame($object, $clone->unwrap());
        $this->assertNotEquals($shared->__debugInfo()['id'], $clone->__debugInfo()['id']);

        $clone->free();
        $shared->free();
    }

    public function testObjectOverflowMoved() {
        $object = new SharedMemoryParcel('hi', 14);
        $awaitable = $object->synchronized(function () {
            return 'hello world';
        });
        \Amp\wait($awaitable);

        $this->assertEquals('hello world', $object->unwrap());
        $object->free();
    }

    /**
     * @group posix
     * @requires extension pcntl
     */
    public function testSetInSeparateProcess() {
        $object = new SharedMemoryParcel(42);

        $this->doInFork(function () use ($object) {
            $awaitable = $object->synchronized(function () {
                return 43;
            });
            \Amp\wait($awaitable);
        });

        $this->assertEquals(43, $object->unwrap());
        $object->free();
    }

    /**
     * @group posix
     * @requires extension pcntl
     */
    public function testFreeInSeparateProcess() {
        $object = new SharedMemoryParcel(42);

        $this->doInFork(function () use ($object) {
            $object->free();
        });

        $this->assertTrue($object->isFreed());
    }
}
