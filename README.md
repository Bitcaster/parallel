# Concurrency for Icicle

**True concurrency using native threading and multiprocessing for parallelizing code, *without* blocking.**

This library is a component for [Icicle](https://github.com/icicleio/icicle) that provides native threading, multiprocessing, process synchronization, shared memory, and task workers. Like other Icicle components, this library uses [Coroutines](//github.com/icicleio/icicle/wiki/Coroutines) built from [Awaitables](https://github.com/icicleio/icicle/wiki/Awaitables) and [Generators](http://www.php.net/manual/en/language.generators.overview.php) to make writing asynchronous code more like writing synchronous code.

[![Build Status](https://img.shields.io/travis/icicleio/concurrent/master.svg?style=flat-square)](https://travis-ci.org/icicleio/concurrent)
[![Coverage Status](https://img.shields.io/coveralls/icicleio/concurrent/master.svg?style=flat-square)](https://coveralls.io/r/icicleio/concurrent)
[![Semantic Version](https://img.shields.io/github/release/icicleio/concurrent.svg?style=flat-square)](http://semver.org)
[![MIT License](https://img.shields.io/packagist/l/icicleio/concurrent.svg?style=flat-square)](LICENSE)
[![@icicleio on Twitter](https://img.shields.io/badge/twitter-%40icicleio-5189c7.svg?style=flat-square)](https://twitter.com/icicleio)

This library provides a means of parallelizing code without littering your application with complicated lock checking and inter-process communication.

To be as flexible as possible, this library comes with a collection of non-blocking concurrency tools that can be used independently as needed, as well as an "opinionated" worker API that allows you to assign units of work to a pool of worker threads or processes.

##### Requirements

- PHP 5.5+

##### Suggested

- [pthreads extension](https://pecl.php.net/package/pthreads): Best extension option for concurrency in PHP, but it requires PHP to be compiled with `--enable-maintainer-zts` to enable thread-safety.
- [pcntl extension](http://php.net/manual/en/book.pcntl.php): Enables forking concurrency method.
- [sysvmsg extension](http://php.net/manual/en/book.sem.php): Required for sharing memory between forks or processes.

##### Installation

The recommended way to install is with the [Composer](http://getcomposer.org/) package manager. (See the [Composer installation guide](https://getcomposer.org/doc/00-intro.md) for information on installing and using Composer.)

Run the following command to use Icicle in your project:

```bash
composer require icicleio/concurrent
```

You can also manually edit `composer.json` to add this library as a project requirement.

```js
// composer.json
{
    "require": {
        "icicleio/concurrent": "^0.3"
    }
}
```

## Documentation

Concurrent can use either process forking, processes created using `proc_open()`, or true threading to parallelize execution. Threading provides better performance and is compatible with Unix and Windows but requires ZTS (Zend thread-safe) PHP, while forking has no external dependencies but is only compatible with Unix systems.

### Threads

Threading is a cross-platform concurrency method that is fast and memory efficient. Thread contexts take advantage of an operating system's multi-threading capabilities to run code in parallel. A spawned thread will run completely parallel to the parent thread, each with its own environment. Each thread is assigned a closure to execute when it is created, and the returned value is passed back to the parent thread. Concurrent goes for a "shared-nothing" architecture, so any variables inside the closure are local to that thread and can store any non-safe data.

You can spawn a new thread with the `Thread::spawn()` method:

```php
use Icicle\Concurrent\Threading\Thread;
use Icicle\Coroutine;
use Icicle\Loop;

Coroutine\create(function () {
    $thread = Thread::spawn(function () {
        print "Hello, World!\n";
    });

    yield $thread->join();
});

Loop\run();
```

You can wait for a thread to finish by calling `join()`. Joining does not block the parent thread and will asynchronously wait for the child thread to finish before resolving.

### Forks

For Unix-like systems, you can create parallel execution using fork contexts. Though not as efficient as multi-threading, in some cases forking can take better advantage of some multi-core processors than threads. Fork contexts use the `pcntl_fork()` function to create a copy of the current process and run alternate code inside the new process.

Spawning and controlling forks are quite similar to creating threads. To spawn a new fork, use the `Fork::spawn()` method:

```php
use Icicle\Concurrent\Forking\Fork;
use Icicle\Coroutine;
use Icicle\Loop;

Coroutine\create(function () {
    $fork = Fork::spawn(function () {
        print "Hello, World!\n";
    });

    yield $fork->join();
});

Loop\run();
```

Calling `join()` on a fork will asynchronously wait for the forked process to terminate, similar to the `pcntl_wait()` function.

#### Synchronization with Channels

Threads and forks wouldn't be very useful if they couldn't be given any data to work on. The recommended way to share data between contexts is with a `Channel`. A channel is a low-level abstraction over local, non-blocking sockets, which can be used to pass messages and objects between two contexts. Channels are non-blocking and do not require locking. For example:

```php
use Icicle\Concurrent\Sync\Channel;
use Icicle\Concurrent\Threading\Thread;
use Icicle\Coroutine;
use Icicle\Loop;

Coroutine\create(function () {
    $thread = Thread::spawn(function () {
        $time = (yield $this->receive()); // Receive from the parent.
        sleep($time);
        yield $this->send("Hello!"); // Send to the parent.
    });

    yield $thread->send(3); // Send 3 to the context.

    $message = (yield $thread->receive()); // Receive from the context.
    yield $thread->join();

    print $message . "\n";
});

Loop\run();
```

Thread and fork execution contexts include a channel to communicate with the parent and context. The channel methods `send()` and `receive()` may be invoked using `$this` within the function executed in the context and on the context object in the parent. See the example above.

#### Synchronization with Parcels

Parcels are shared containers that allow you to store context-safe data inside a shared location so that it can be accessed by multiple contexts. To prevent race conditions, you still need to access a parcel's data exclusively, but Concurrent allows you to acquire a lock on a parcel asynchronously without blocking the context execution, unlike traditional mutexes.

### Development and Contributing

Interested in contributing to Icicle? Please see our [contributing guidelines](https://github.com/icicleio/icicle/blob/master/CONTRIBUTING.md) in the [Icicle repository](https://github.com/icicleio/icicle).

Want to hack on the source? A [Vagrant](http://vagrantup.com) box is provided with the repository to give a common development environment for running concurrent threads and processes, and comes with a bunch of handy tools and scripts for testing and experimentation.

Starting up and logging into the virtual machine is as simple as

    vagrant up && vagrant ssh

Once inside the VM, you can install PHP extensions with [Pickle](https://github.com/FriendsOfPHP/pickle), switch versions with `newphp VERSION`, and test for memory leaks with [Valgrind](http://valgrind.org).
