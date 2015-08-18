<?php
namespace Icicle\Concurrent\Threading;

use Icicle\Concurrent\ExecutorInterface;
use Icicle\Concurrent\Sync\Channel;
use Icicle\Concurrent\Sync\Lock;
use Icicle\Coroutine;

class ThreadExecutor implements ExecutorInterface
{
    /**
     * @var \Icicle\Concurrent\Threading\Thread
     */
    private $thread;

    /**
     * @var \Icicle\Concurrent\ChannelInterface
     */
    private $channel;

    /**
     * @param \Icicle\Concurrent\Threading\Thread
     * @param \Icicle\Concurrent\Sync\Channel $channel
     */
    public function __construct(Thread $thread, Channel $channel)
    {
        $this->thread = $thread;
        $this->channel = $channel;
    }

    /**
     * {@inheritdoc}
     */
    public function receive()
    {
        return $this->channel->receive();
    }

    /**
     * {@inheritdoc}
     */
    public function send($data)
    {
        return $this->channel->send($data);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return $this->channel->close();
    }

    /**
     * {@inheritdoc}
     */
    public function acquire()
    {
        while (!$this->thread->tsl()) {
            yield Coroutine\sleep(0.01);
        }

        yield new Lock(function () {
            $this->thread->release();
        });
    }
}