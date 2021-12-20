<?php

namespace Amp\Parallel\Test\Worker\Fixtures;

use Amp\Cache\Cache;
use Amp\Cancellation;
use Amp\Parallel\Sync\Channel;
use Amp\Parallel\Worker\Task;

class NonAutoloadableResultTask implements Task
{
    public function run(Channel $channel, Cache $cache, Cancellation $cancellation): mixed
    {
        require __DIR__ . "/non-autoloadable-class.php";
        return new NonAutoloadableClass;
    }
}
