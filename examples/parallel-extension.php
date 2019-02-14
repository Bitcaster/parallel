#!/usr/bin/env php
<?php
require \dirname(__DIR__).'/vendor/autoload.php';

use Amp\Delayed;
use Amp\Loop;
use Amp\Parallel\Context\Parallel;

Loop::run(function () {
    $timer = Loop::repeat(1000, function () {
        static $i;
        $i = $i ? ++$i : 1;
        print "Demonstrating how alive the parent is for the {$i}th time.\n";
    });

    try {
        // Create a new child thread that does some blocking stuff.
        $context = yield Parallel::run(__DIR__ . "/blocking-process.php");

        \assert($context instanceof Parallel);

        print "Waiting 2 seconds to send start data...\n";
        yield new Delayed(2000);

        yield $context->send("Start data"); // Data sent to child process, received on line 9 of blocking-process.php

        \printf("Received the following from child: %s\n", yield $context->receive()); // Sent on line 14 of blocking-process.php
        \printf("Process ended with value %d!\n", yield $context->join());
    } finally {
        Loop::cancel($timer);
    }
});
