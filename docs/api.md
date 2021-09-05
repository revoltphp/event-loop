This document describes the `Revolt\EventLoop\Loop` accessor. You might want to also read the documentation contained in
the source file, it's extensively documented and doesn't contain much distracting code.

## `createSuspension()`

The primary way an application interacts with the event loop is to schedule events for
execution. `EventLoop::createSuspension()` followed by `$suspension->suspend()` runs the event loop indefinitely until there
are no watchable timer events, IO streams or signals remaining to watch, or the suspension resumed.

## Timers

The event loop exposes several ways to schedule timers. Let's look at some details for each function.

### `defer()`

- Schedules a callback to execute in the next iteration of the event loop.
- This method guarantees a clean call stack to avoid starvation of other events in the current iteration of the loop.
  A `defer` callback is *always* executed in the next tick of the event loop.
- After an `defer` timer executes, it is automatically garbage collected by the event loop so there is no need
  for applications to manually cancel the associated timer.
- Like all watchers, `defer` timers may be disabled and re-enabled. If you disable this watcher between the time you
  schedule it and the time that it actually runs the event loop *will not* be able to garbage collect it until it
  executes. Therefore, you must manually cancel a `defer` watcher yourself if it never actually executes to free any
  associated resources.

**Example**

```php
<?php // using EventLoop::defer()

use Revolt\EventLoop;

echo "line 1\n";

EventLoop::defer(function (): void {
    echo "line 3\n";
});

echo "line 2\n";

EventLoop::run();
```

**Callback Signature**

`function (string $watcherId)`

### `delay()`

- Schedules a callback to execute after a delay of `n` seconds
- A `delay` watcher is also automatically garbage collected by the event loop after execution and applications should not
  manually cancel it unless they wish to discard the watcher entirely prior to execution.
- A `delay` watcher that is disabled has its delay time reset so that the original delay time starts again from zero
  once re-enabled.
- Like `defer` watchers, a timer scheduled for one-time execution must be manually canceled to free resources if it
  never runs due to being disabled by the application after creation.

**Example**

```php
<?php // using EventLoop::delay()

use Revolt\EventLoop;

EventLoop::delay(3, function (): void {
    print '3 seconds passed';
});

EventLoop::run();
```

**Callback Signature**

`function (string $watcherId)`

### `repeat()`

- Schedules a callback to repeatedly execute every `n` seconds.
- Like all other watchers, `repeat` timers may be disabled/re-enabled at any time.
- Unlike `defer()` and `delay()` watchers, `repeat()` timers must be explicitly canceled to free associated resources.
  Failure to free `repeat` watchers via `cancel()` once their purpose is fulfilled will result in memory leaks in your
  application. It is not enough to simply disable repeat watchers as their data is only freed upon cancellation.

```php
<?php // using EventLoop::repeat()

use Revolt\EventLoop;

EventLoop::repeat(0.1, function ($watcherId): void {
    static $i = 0;

    if ($i++ < 3) {
        echo "tick\n";
    } else {
        EventLoop::cancel($watcherId);
    }
});

EventLoop::run();
```

**Callback Signature**

`function (string $watcherId): void`

## Stream IO Watchers

Stream watchers are how we know when we can read and write to sockets and other streams. These events are how we're able
to actually create things like HTTP servers and asynchronous database libraries using the event loop. As such, stream IO
watchers form the backbone of any useful non-blocking, concurrent application.

There are two types of IO watchers:

- Readability watchers
- Writability watchers

### `onReadable()`

> This is an advanced low-level API. Most users should use a stream abstraction instead.

Watchers registered via `EventLoop::onReadable()` trigger their callbacks in the following situations:

- When data is available to read on the stream under observation
- When the stream is at EOF (for sockets, this means the connection is broken)

A common usage pattern for reacting to readable data looks something like this example:

```php
<?php

use Revolt\EventLoop;

const IO_GRANULARITY = 32768;

function isStreamDead($socket): bool {
    return !is_resource($socket) || @feof($socket);
}

EventLoop::onReadable($socket, function ($watcherId, $socket) {
    $socketId = (int) $socket;
    $newData = @fread($socket, IO_GRANULARITY);
    if ($newData != "") {
        // There was actually data and not an EOF notification. Let's consume it!
        parseIncrementalData($socketId, $newData);
    } elseif (isStreamDead($socket)) {
        EventLoop::cancel($watcherId);
    }
});

EventLoop::run();
```

In the above example we've done a few very simple things:

- Register a readability watcher for a socket that will trigger our callback when there is data available to read.
- When we read data from the stream in our triggered callback we pass that to a stateful parser that does something
  domain-specific when certain conditions are met.
- If the `fread()` call indicates that the socket connection is dead we clean up any resources we've allocated for the
  storage of this stream. This process should always include calling `EventLoop::cancel()` on any event loop watchers we
  registered in relation to the stream.

> You should always read a multiple of the configured chunk size (default: 8192), otherwise your code might not work as expected with loop backends other than `stream_select()`, see [amphp/amp#65](https://github.com/amphp/amp/issues/65) for more information.

### `onWritable()`

> This is an advanced low-level API. Most users should use a stream abstraction instead.

- Streams are essentially *"always"* writable. The only time they aren't is when their respective write buffers are
  full.

A common usage pattern for reacting to writability involves initializing a writability watcher without enabling it when
a client first connects to a server. Once incomplete writes occur we're then able to "unpause" the write watcher
using `EventLoop::enable()` until data is fully sent without having to create and cancel new watcher resources on the same
stream multiple times.

## Pausing, Resuming and Canceling Watchers

All watchers, regardless of type, can be temporarily disabled and enabled in addition to being cleared
via `EventLoop::cancel()`. This allows for advanced capabilities such as disabling the acceptance of new socket clients in
server applications when simultaneity limits are reached. In general, the performance characteristics of watcher reuse
via pause/resume are favorable by comparison to repeatedly canceling and re-registering watchers.

### `disable()`

A simple disable example:

```php
<?php

use Revolt\EventLoop;

// Register a watcher we'll disable
$watcherIdToDisable = EventLoop::delay(1, function (): void {
    echo "I'll never execute in one second because: disable()\n";
});

// Register a watcher to perform the disable() operation
EventLoop::delay(0.5, function () use ($watcherIdToDisable) {
    echo "Disabling WatcherId: ", $watcherIdToDisable, "\n";
    EventLoop::disable($watcherIdToDisable);
});

EventLoop::run();
```

After our second watcher callback executes the event loop exits because there are no longer any enabled watchers
registered to process.

### `enable()`

`enable()` is the diametric analog of the `disable()` example demonstrated above:

```php
<?php

use Revolt\EventLoop;

// Register a watcher
$myWatcherId = EventLoop::repeat(1, function(): void {
    echo "tick\n";
});

// Disable the watcher
EventLoop::disable($myWatcherId);

EventLoop::defer(function () use ($myWatcherId): void {
    // Immediately enable the watcher when the event loop starts
    EventLoop::enable($myWatcherId);
    // Now that it's enabled we'll see tick output in our console every second.
});

EventLoop::run();
```

### `cancel()`

It's important to *always* cancel persistent watchers once you're finished with them, or you'll create memory leaks in
your application. This functionality works in exactly the same way as the above `enable` / `disable` examples:

```php
<?php

use Revolt\EventLoop;

$myWatcherId = EventLoop::repeat(1, function (): void {
    echo "tick\n";
});

// Cancel $myWatcherId in five seconds and exit the event loop
EventLoop::delay(5, function () use ($myWatcherId): void {
    EventLoop::cancel($myWatcherId);
});

EventLoop::run();
```

## `onSignal()`

`EventLoop::onSignal()` can be used to react to signals sent to the process.

```php
<?php

use Revolt\EventLoop;

// Let's tick off output once per second, so we can see activity.
EventLoop::repeat(1, function (): void {
    echo "tick: ", date('c'), "\n";
});

// What to do when a SIGINT signal is received
$watcherId = EventLoop::onSignal(SIGINT, function (): void {
    echo "Caught SIGINT! exiting ...\n";
    exit;
});

EventLoop::run();
```

As should be clear from the above example, signal watchers may be enabled, disabled and canceled like any other event.

## Referencing Watchers

Watchers can either be referenced or unreferenced. An unreferenced watcher doesn't keep the loop alive. All watchers are
referenced by default.

One example to use unreferenced watchers is when using signal watchers. Generally, if all watchers are gone and only the
signal watcher still exists, you want to exit the loop unless you're not actively waiting for that event to happen.

### `reference()`

Marks a watcher as referenced. Takes the `$watcherId` as first and only argument.

### `unreference()`

Marks a watcher as unreferenced. Takes the `$watcherId` as first and only argument.

## Event Loop Addenda

### Watcher Callback Parameters

Watcher callbacks are invoked using the following standardized parameter order:

| Watcher Type            | Callback Signature                     |
| ----------------------- | ---------------------------------------|
| `defer()`               | `function(string $watcherId)`          |
| `delay()`               | `function(string $watcherId)`          |
| `repeat()`              | `function(string $watcherId)`          |
| `onReadable()`          | `function(string $watcherId, $stream)` |
| `onWritable()`          | `function(string $watcherId, $stream)` |
| `onSignal()`            | `function(string $watcherId, $signo)`  |

### Watcher Cancellation Safety

It is always safe to cancel a watcher from within its own callback. For example:

```php
<?php

use Revolt\EventLoop;

$increment = 0;

EventLoop::repeat(0.1, function ($watcherId) use (&$increment): void {
    echo "tick\n";
    if (++$increment >= 3) {
        EventLoop::cancel($watcherId); // <-- cancel myself!
    }
});

EventLoop::run();
```

It is also always safe to cancel a watcher from multiple places. A double-cancel will simply be ignored.

### An Important Note on Writability

Because streams are essentially *"always"* writable you should only enable writability watchers while you have data to
send. If you leave these watchers enabled when your application doesn't have anything to write the watcher will trigger
endlessly until disabled or canceled. This will max out your CPU. If you're seeing inexplicably high CPU usage in your
application it's a good bet you've got a writability watcher that you failed to disable or cancel after you were
finished with it.

A standard pattern in this area is to initialize writability watchers in a disabled state before subsequently enabling
them at a later time as shown here:

```php
<?php

use Revolt\EventLoop;

$watcherId = EventLoop::onWritable(STDOUT, function (): void {});
EventLoop::disable($watcherId);
// ...
EventLoop::enable($watcherId);
// ...
EventLoop::disable($watcherId);
```

### Process Signal Number Availability

`ext-uv` exposes `UV::SIG*` constants for watchable signals. Applications using the `EventDriver` will need to manually
specify the appropriate integer signal numbers when registering signal watchers or rely on `ext-pcntl`.

### Timer Drift

Repeat timers are basically simple delay timers that are automatically rescheduled right before the appropriate handler
is triggered. They are subject to timer drift. Multiple timers might stack up in case they execute as coroutines.
