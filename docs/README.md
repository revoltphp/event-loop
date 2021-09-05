It may surprise people to learn that the PHP standard library already has everything we need to write event-driven and non-blocking applications. We only reach the limits of native PHP's functionality in this area when we ask it to poll thousands of file descriptors for IO activity at the same time. Even in this case, though, the fault is not with PHP but the underlying system `select()` call which is linear in its performance degradation as load increases.

For performance that scales out to high volume we require more advanced capabilities currently found only in extensions. If you wish to, for example, service 10,000 simultaneous clients in an event loop backed socket server, you should use one of the event loop implementations based on a PHP extension. However, if you're using the package in a strictly local program for non-blocking concurrency, or you don't need to handle more than a few hundred simultaneous clients in a server application, the native PHP functionality should be adequate.

## Global Accessor

The package uses a global accessor for the event loop (scheduler) as there's only one event loop for each application. It doesn't make sense to have two loops running at the same time, as they would just have to schedule each other in a busy waiting manner to operate correctly.

The event loop should be accessed through the methods provided by `Revolt\EventLoop`. On the first use of the accessor, it will automatically create the best available driver, see next section.

`Revolt\EventLoop::setDriver()` can be used to set a custom driver. You can clear the scheduler in tests so each test runs with a fresh state to achieve test isolation.

## Implementations

The package offers different event loop implementations based on various backends. All implementations implement `Revolt\EventLoop\Driver`. Each behaves exactly the same way from an external API perspective. The main differences have to do with underlying performance characteristics. The current implementations are listed here:

| Class                     | Extension                                              | Repository |
| ------------------------- | ------------------------------------------------------ | ---------- |
| `Revolt\EventLoop\Driver\StreamSelectDriver` | –                                                      | -          |
| `Revolt\EventLoop\Driver\EvDriver`           | [`pecl/ev`](https://pecl.php.net/package/ev)           | [`php-ev`](https://bitbucket.org/osmanov/pecl-ev) |
| `Revolt\EventLoop\Driver\EventDriver`        | [`pecl/event`](https://pecl.php.net/package/event)     | [`pecl-event`](https://bitbucket.org/osmanov/pecl-event) |
| `Revolt\EventLoop\Driver\UvDriver`           | [`pecl/uv`](https://pecl.php.net/package/uv)           | [`php-uv`](https://github.com/amphp/ext-uv) |

It's not important to choose one implementation for your application. The package will automatically select the best available driver. It's perfectly fine to have one of the extensions in production while relying on the `StreamSelectDriver` locally for development.

If you want to quickly switch implementations during development, e.g. for comparison or testing, you can set the `REVOLT_LOOP_DRIVER` environment variable to one of the classes. If you use a custom implementation, this only works if the implementation's constructor doesn't take any arguments.

## Event Loop as Task Scheduler

The first thing we need to understand to program effectively using an event loop is this:

**The event loop is our task scheduler.**

The event loop controls the program flow as long as it runs. Once we tell the event loop to run it will maintain control until the application errors out, has nothing left to do, or is explicitly stopped.

Consider this very simple example:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Revolt\EventLoop;

$suspension = EventLoop::createSuspension();

$repeatWatcher = EventLoop::repeat(1000, function (): void {
    print "++ Executing watcher created by EventLoop::repeat()" . PHP_EOL;
});

EventLoop::delay(5000, function () use ($suspension, $repeatWatcher): void {
    print "++ Executing watcher created by EventLoop::delay()" . PHP_EOL;

    EventLoop::cancel($repeatWatcher);
    $suspension->resume(null);

    print "++ Executed after script ended" . PHP_EOL;
});

$suspension->suspend();

print '++ Script end' . PHP_EOL;
```

Upon execution of the above example you should see output like this:

```plain
++ Executing watcher created by EventLoop::repeat()
++ Executing watcher created by EventLoop::repeat()
++ Executing watcher created by EventLoop::repeat()
++ Executing watcher created by EventLoop::repeat()
++ Executing watcher created by EventLoop::delay()
++ Script end
++ Executed after script ended
```

This output demonstrates that what happens inside the event loop is like its own separate program. Your script will not continue past the point of `$suspension->yield()` unless the suspension point is resumed with `$suspension->resume()` or `$suspension->throw()`.

While an application can and often does take place entirely inside the confines of the event loop, we can also use the event loop to do things like the following example which imposes a short-lived timeout for interactive console input:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Revolt\EventLoop;

if (\stream_set_blocking(STDIN, false) !== true) {
    \fwrite(STDERR, "Unable to set STDIN to non-blocking" . PHP_EOL);
    exit(1);
}

print "Write something and hit enter" . PHP_EOL;

$suspension = EventLoop::createSuspension();

$readWatcher = EventLoop::onReadable(STDIN, function ($watcherId, $stream) use ($suspension): void {
    EventLoop::cancel($watcherId);

    $chunk = \fread($stream, 8192);

    print "Read " . \strlen($chunk) . " bytes" . PHP_EOL;

    $suspension->resume(null);
});

$timeoutWatcher = EventLoop::delay(5000, fn () => $suspension->resume(null));

$suspension->suspend();

EventLoop::cancel($readWatcher);
EventLoop::cancel($timeoutWatcher);
```

Obviously we could have simply used `fgets(STDIN)` synchronously in this example. We're just demonstrating that it's possible to move in and out of the event loop to mix synchronous tasks with non-blocking tasks as needed.

Continue with the [Event Loop API](api.md).
