<?php

namespace Amp\Parallel\Test\Sync;

use Amp\Parallel\Context\Thread;
use Amp\Parallel\Sync\Channel;
use Amp\Parallel\Sync\ThreadedParcel;
use Amp\Promise;
use Amp\Success;

/**
 * @requires extension pthreads
 */
class ThreadedParcelTest extends AbstractParcelTest
{
    protected function createParcel($value): Promise
    {
        return new Success(new ThreadedParcel($value));
    }

    public function testWithinThread()
    {
        $value = 1;
        $parcel = new ThreadedParcel($value);

        $thread = yield Thread::run(function (Channel $channel, ThreadedParcel $parcel) {
            $parcel->synchronized(function (int $value) {
                return $value + 1;
            });
            return 0;
        }, $parcel);

        $this->assertSame(0, yield $thread->join());
        $this->assertSame($value + 1, yield $parcel->unwrap());
    }
}
