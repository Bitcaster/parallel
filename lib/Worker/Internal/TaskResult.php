<?php

namespace Amp\Concurrent\Worker\Internal;

use Interop\Async\Awaitable;

interface TaskResult {
    /**
     * @return string Task identifier.
     */
    public function getId(): string;
    
    /**
     * @return \Interop\Async\Awaitable<mixed> Resolved with the task result or failure reason.
     */
    public function getAwaitable(): Awaitable;
}