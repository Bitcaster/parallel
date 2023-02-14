<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Worker\FetchTask;
use function Amp\Future\await;
use function Amp\Parallel\Worker\workerPool;

$start = microtime(true);

// FetchTask uses file_get_contents to demonstrate how blocking I/O can be used in child processes
// However, if you're working with HTTP, use amphp/http-client instead of amphp/parallel
$execution1 = workerPool()->submit(new FetchTask('https://amphp.org'));
$execution2 = workerPool()->submit(new FetchTask('https://github.com'));

$bodies = await([
    $execution1->getResult(),
    $execution2->getResult(),
]);

var_dump(strlen($bodies[0]));
var_dump(strlen($bodies[1]));

print PHP_EOL;
print 'Took ' . (microtime(true) - $start) . ' seconds' . PHP_EOL;