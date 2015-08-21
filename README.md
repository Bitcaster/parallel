# Concurrency Component for Icicle
**Under development -- keep an eye out for things to come in the near future though!**

Concurrent provides a means of parallelizing code without littering your application with complicated lock checking and inter-process communication.

To be as flexible as possible, Concurrent comes with a collection of non-blocking concurrency tools that can be used independently as needed, as well as an "opinionated" task API that allows you to assign units of work to a pool of worker threads or processes.

#### Requirements
- PHP 5.5+
- [pthreads](http://pthreads.org) for multithreading *or*
- System V-compatible Unix OS and PHP with `--enable-pcntl`

#### Benchmarks
A few benchmarks are provided for analysis and study. Can be used to back up implementation decisions, or to measure performance on different platforms or hardware.

    vendor/bin/athletic -p benchmarks -b vendor/autoload.php

## Documentation
Concurrent can use either process forking or true threading to parallelize execution. Threading provides better performance and is compatible with Unix and Windows but requires ZTS (Zend thread-safe) PHP, while forking has no external dependencies but is only compatible with Unix systems. If your environment works meets neither of these requirements, this library won't work.

### Contexts
Concurrent provides a generic interface for working with parallel tasks called "contexts". All contexts are capable of being executed in parallel from the main program code. Each context is assigned a closure to execute when it is created, and the returned value is passed back to the parent context. Concurrent goes for a "shared-nothing" architecture, so any variables inside the closure are local to that context and can store any non-safe data.

You can wait for a context to close by calling `join()`. Joining does not block the parent context and will asynchronously wait for the child context to finish before resolving.

```php
use Icicle\Concurrent\Threading\ThreadContext;
use Icicle\Coroutine;
use Icicle\Loop;

Coroutine\create(function () {
    $thread = new ThreadContext(function () {
        print "Hello, World!\n";
    });

    $thread->start();
    yield $thread->join();
});

Loop\run();
```

#### Synchronization with channels
Contexts wouldn't be very useful if they couldn't be given any data to work on. The recommended way to share data between contexts is with a `Channel`. A channel is a low-level abstraction over local, non-blocking sockets, which can be used to pass messages and objects between two contexts. Channels are non-blocking and do not require locking. For example:

```php
use Icicle\Concurrent\Sync\Channel;
use Icicle\Concurrent\Threading\ThreadContext;
use Icicle\Coroutine;
use Icicle\Loop;

Coroutine\create(function () {
    list($socketA, $socketB) = Channel::createSocketPair();
    $channel = new Channel($socketA);

    $thread = new ThreadContext(function ($socketB) {
        $channel = new Channel($socketB);
        yield $channel->send("Hello!");
    }, $socketB);

    $thread->start();
    $message = (yield $channel->receive());
    yield $thread->join();
});

Loop\run();
```

### Synchronization with parcels
Parcels are shared containers that allow you to store context-safe data inside a shared location so that it can be accessed by multiple contexts. To prevent race conditions, you still need to access a parcel's data exclusively, but Concurrent allows you to acquire a lock on a parcel asynchronously without blocking the context execution, unlike traditional mutexes.

### Threading
Threading is a cross-platform concurrency method that is fast and memory efficient. Thread contexts take advantage of an operating system's multi-threading capabilities to run code in parallel.

### Forking
For Unix-like systems, you can create parallel execution using fork contexts. Though not as efficient as multi-threading, in some cases forking can take better advantage of some multi-core processors than threads. Fork contexts use the `pcntl_fork()` function to create a copy of the current process and run alternate code inside the new process.

## License
All documentation and source code is licensed under the Apache License, Version 2.0 (Apache-2.0). See the [LICENSE](LICENSE) file for details.
