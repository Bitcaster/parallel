<?php
namespace Icicle\Tests\Concurrent\Sync;

use Icicle\Concurrent\Sync\ChannelledStream;
use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Stream\{DuplexStream, ReadableStream};
use Icicle\Stream\Exception\{UnreadableException, UnwritableException};
use Icicle\Tests\Concurrent\TestCase;

class ChannelledStreamTest extends TestCase
{
    /**
     * @return \Icicle\Stream\DuplexStream|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function createMockStream()
    {
        $mock = $this->getMock(DuplexStream::class);

        $buffer = '';

        $mock->method('write')
            ->will($this->returnCallback(function ($data) use (&$buffer) {
                $buffer .= $data;
                return yield strlen($data);
            }));

        $mock->method('read')
            ->will($this->returnCallback(function ($length, $byte = null, $timeout = 0) use (&$buffer) {
                $result = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);
                return yield $result;
            }));

        return $mock;
    }

    /**
     * @expectedException \Icicle\Exception\InvalidArgumentError
     */
    public function testReadableWithoutWritable()
    {
        $mock = $this->getMock(ReadableStream::class);

        $channel = new ChannelledStream($mock);
    }

    public function testSendReceive()
    {
        Coroutine\create(function () {
            $mock = $this->createMockStream();
            $a = new ChannelledStream($mock);
            $b = new ChannelledStream($mock);

            $message = 'hello';

            yield from $a->send($message);
            $data = yield from $b->receive();
            $this->assertSame($message, $data);
        })->done();

        Loop\run();
    }

    /**
     * @depends testSendReceive
     */
    public function testSendReceiveLongData()
    {
        Coroutine\create(function () {
            $mock = $this->createMockStream();
            $a = new ChannelledStream($mock);
            $b = new ChannelledStream($mock);

            $length = 0xffff;
            $message = '';
            for ($i = 0; $i < $length; ++$i) {
                $message .= chr(mt_rand(0, 255));
            }

            yield from $a->send($message);
            $data = yield from $b->receive();
            $this->assertSame($message, $data);
        })->done();

        Loop\run();
    }

    /**
     * @depends testSendReceive
     * @expectedException \Icicle\Concurrent\Exception\ChannelException
     */
    public function testInvalidDataReceived()
    {
        Coroutine\create(function () {
            $mock = $this->createMockStream();
            $a = new ChannelledStream($mock);
            $b = new ChannelledStream($mock);

            // Close $a. $b should close on next read...
            yield from $mock->write(pack('L', 10) . '1234567890');
            $data = yield from $b->receive();
        })->done();

        Loop\run();
    }

    /**
     * @depends testSendReceive
     * @expectedException \Icicle\Concurrent\Exception\ChannelException
     */
    public function testSendUnserializableData()
    {
        Coroutine\create(function () {
            $mock = $this->createMockStream();
            $a = new ChannelledStream($mock);
            $b = new ChannelledStream($mock);

            // Close $a. $b should close on next read...
            yield from $a->send(function () {});
            $data = yield from $b->receive();
        })->done();

        Loop\run();
    }

    /**
     * @depends testSendReceive
     * @expectedException \Icicle\Concurrent\Exception\ChannelException
     */
    public function testSendAfterClose()
    {
        Coroutine\create(function () {
            $mock = $this->getMock(DuplexStream::class);
            $mock->expects($this->once())
                ->method('write')
                ->will($this->throwException(new UnwritableException()));

            $a = new ChannelledStream($mock);
            $b = new ChannelledStream($this->getMock(DuplexStream::class));

            yield from $a->send('hello');
        })->done();

        Loop\run();
    }

    /**
     * @depends testSendReceive
     * @expectedException \Icicle\Concurrent\Exception\ChannelException
     */
    public function testReceiveAfterClose()
    {
        Coroutine\create(function () {
            $mock = $this->getMock(DuplexStream::class);
            $mock->expects($this->once())
                ->method('read')
                ->will($this->throwException(new UnreadableException()));

            $a = new ChannelledStream($mock);

            $data = yield from $a->receive();
        })->done();

        Loop\run();
    }
}
