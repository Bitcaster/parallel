<?php

namespace Amp\Parallel\Context;

interface ContextFactory
{
    /**
     * @template TValue
     *
     * Creates a new execution context.
     *
     * @param string|string[] $script Path to PHP script or array with first element as path and following elements
     *     options to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     *
     * @return Context<TValue>
     */
    public function create(string|array $script): Context;
}
