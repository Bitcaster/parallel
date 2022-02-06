<?php

namespace Amp\Parallel\Context;

use Amp\Parallel\Sync\IpcHub;
use Revolt\EventLoop;

/**
 * @template TResult
 * @template TReceive
 * @template TSend
 *
 * @param string|string[] $script Path to PHP script or array with first element as path and following elements
 *     options to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
 *
 * @return Context<TResult, TReceive, TSend>
 */
function startContext(string|array $script): Context
{
    return contextFactory()->start($script);
}

/**
 * Gets or sets the global context factory.
 *
 * @param ContextFactory|null $factory
 *
 * @return ContextFactory
 */
function contextFactory(?ContextFactory $factory = null): ContextFactory
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($factory) {
        return $map[$driver] = $factory;
    }

    return $map[$driver] ??= new DefaultContextFactory();
}

/**
 * Gets the global shared IpcHub instance.
 *
 * @return IpcHub
 */
function ipcHub(): IpcHub
{
    static $hubs;
    $hubs ??= new \WeakMap();
    return $hubs[EventLoop::getDriver()] ??= new IpcHub();
}
