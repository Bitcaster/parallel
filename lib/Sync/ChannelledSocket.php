<?php

namespace Amp\Parallel\Sync;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\Future;
use Amp\Serialization\Serializer;

final class ChannelledSocket implements Channel
{
    private ChannelledStream $channel;

    private ReadableResourceStream $read;

    private WritableResourceStream $write;

    /**
     * @param resource        $read Readable stream resource.
     * @param resource        $write Writable stream resource.
     * @param Serializer|null $serializer
     *
     * @throws \Error If a stream resource is not given for $resource.
     */
    public function __construct($read, $write, ?Serializer $serializer = null)
    {
        $this->channel = new ChannelledStream(
            $this->read = new ReadableResourceStream($read),
            $this->write = new WritableResourceStream($write),
            $serializer
        );
    }

    public function receive(?Cancellation $token = null): mixed
    {
        return $this->channel->receive($token);
    }

    public function send(mixed $data): void
    {
        $this->channel->send($data);
    }

    public function unreference(): void
    {
        $this->read->unreference();
    }

    public function reference(): void
    {
        $this->read->reference();
    }

    /**
     * @return resource|null
     */
    public function getResource()
    {
        return $this->read->getResource();
    }

    public function isClosed(): bool
    {
        return $this->read->isClosed() || $this->write->isClosed();
    }

    /**
     * Closes the read and write resource streams.
     */
    public function close(): void
    {
        $this->read->close();
        $this->write->close();
    }
}
