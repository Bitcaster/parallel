<?php
namespace Icicle\Concurrent;

interface Context
{
    /**
     * @return bool
     */
    public function isRunning(): bool;

    /**
     * Starts the execution context.
     */
    public function start();

    /**
     * Immediately kills the context.
     */
    public function kill();

    /**
     * @coroutine
     *
     * @return \Generator
     *
     * @resolve mixed
     */
    public function join(): \Generator;
}
