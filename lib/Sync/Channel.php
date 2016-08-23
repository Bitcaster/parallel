<?php declare(strict_types = 1);

namespace Amp\Parallel\Sync;

use Interop\Async\Awaitable;

/**
 * Interface for sending messages between execution contexts.
 */
interface Channel {
    /**
     * @return \Interop\Async\Awaitable<mixed>
     *
     * @throws \Amp\Parallel\StatusError Thrown if the context has not been started.
     * @throws \Amp\Parallel\SynchronizationError If the context has not been started or the context
     *     unexpectedly ends.
     * @throws \Amp\Parallel\ChannelException If receiving from the channel fails.
     * @throws \Amp\Parallel\SerializationException If unserializing the data fails.
     */
    public function receive(): Awaitable;

    /**
     * @param mixed $data
     *
     * @return \Interop\Async\Awaitable<int> Resolves with the number of bytes sent on the channel.
     *
     * @throws \Amp\Parallel\StatusError Thrown if the context has not been started.
     * @throws \Amp\Parallel\SynchronizationError If the context has not been started or the context
     *     unexpectedly ends.
     * @throws \Amp\Parallel\ChannelException If sending on the channel fails.
     * @throws \Error If an ExitStatus object is given.
     * @throws \Amp\Parallel\SerializationException If serializing the data fails.
     */
    public function send($data): Awaitable;
}
