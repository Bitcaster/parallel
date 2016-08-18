#!/usr/bin/env php
<?php

use Amp\Concurrent\{ ChannelException, SerializationException} ;
use Amp\Concurrent\Sync\{ ChannelledStream, Internal\ExitFailure, Internal\ExitSuccess };
use Amp\Concurrent\Worker\{ BasicEnvironment, Internal\TaskRunner };
use Amp\Socket\Socket;

// Redirect all output written using echo, print, printf, etc. to STDERR.
ob_start(function ($data) {
    fwrite(STDERR, $data);
    return '';
}, 1, 0);

(function () {
    $paths = [
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'autoload.php',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    ];
    
    $autoloadPath = null;
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $autoloadPath = $path;
            break;
        }
    }
    
    if (null === $autoloadPath) {
        fwrite(STDERR, 'Could not locate autoload.php.');
        exit(1);
    }
    
    require $autoloadPath;
})();

Amp\execute(function () {
    $channel = new ChannelledStream(new Socket(STDIN), new Socket(STDOUT));
    $environment = new BasicEnvironment;
    $runner = new TaskRunner($channel, $environment);

    try {
        $result = new ExitSuccess(yield $runner->run());
    } catch (Throwable $exception) {
        $result = new ExitFailure($exception);
    }

    // Attempt to return the result.
    try {
        try {
            return yield $channel->send($result);
        } catch (SerializationException $exception) {
            // Serializing the result failed. Send the reason why.
            return yield $channel->send(new ExitFailure($exception));
        }
    } catch (ChannelException $exception) {
        // The result was not sendable! The parent context must have died or killed the context.
        return 0;
    }
});
