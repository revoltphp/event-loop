<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\InvalidWatcherError;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Watchers (enabled or new watchers) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
 *
 * All registered callbacks MUST NOT be called from a file with strict types enabled (`declare(strict_types=1)`).
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Next watcher ID. */
    private string $nextId = "a";

    private \Fiber $fiber;

    /** @var Watcher[] */
    private array $watchers = [];

    /** @var Watcher[] */
    private array $enableQueue = [];

    /** @var Watcher[] */
    private array $deferQueue = [];

    /** @var Watcher[] */
    private array $nextTickQueue = [];

    /** @var array<int, array<int, callable|list<mixed>>> */
    private array $microQueue = [];

    /** @var callable(\Throwable):void|null */
    private $errorHandler;

    private bool $running = false;

    public function __construct()
    {
        $this->fiber = $this->createFiber();
    }

    /**
     * Run the event loop.
     *
     * One iteration of the loop is called one "tick". A tick covers the following steps:
     *
     *  1. Activate watchers created / enabled in the last tick / before `run()`.
     *  2. Execute all enabled defer watchers.
     *  3. Execute all due timer, pending signal and actionable stream callbacks, each only once per tick.
     *
     * The loop MUST continue to run until it is either stopped explicitly, no referenced watchers exist anymore, or an
     * exception is thrown that cannot be handled. Exceptions that cannot be handled are exceptions thrown from an
     * error handler or exceptions that would be passed to an error handler but none exists to handle them.
     *
     * @throw \Error Thrown if the event loop is already running.
     */
    public function run(): void
    {
        if ($this->running) {
            throw new \Error("The loop was already running");
        }

        $this->running = true;

        try {
            while ($this->running) {
                $this->invokeMicrotasks();

                if ($this->isEmpty()) {
                    return;
                }

                $this->tick();
            }
        } finally {
            $this->stop();
        }
    }

    /**
     * Stop the event loop.
     *
     * When an event loop is stopped, it continues with its current tick and exits the loop afterwards. Multiple calls
     * to stop MUST be ignored and MUST NOT raise an exception.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @return bool True if the event loop is running, false if it is stopped.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Queue a microtask.
     *
     * The queued callable MUST be executed immediately once the event loop gains control. Order of queueing MUST be
     * preserved when executing the callbacks. Recursive scheduling can thus result in infinite loops, use with care.
     *
     * Does NOT create a watcher, thus CAN NOT be marked as disabled or unreferenced. Use {@see EventLoop::defer()} if
     * you need these features.
     *
     * @param callable $callback The callback to queue.
     * @param mixed ...$args The callback arguments.
     */
    public function queue(callable $callback, mixed ...$args): void
    {
        $this->microQueue[] = [$callback, $args];
    }

    /**
     * Defer the execution of a callback.
     *
     * The deferred callable MUST be executed before any other type of watcher in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param callable(string):void $callback The callback to defer. The `$watcherId` will be
     *     invalidated before the callback call.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function defer(callable $callback): string
    {
        $watcher = new DeferWatcher($this->nextId++, $callback);

        $this->watchers[$watcher->id] = $watcher;
        $this->nextTickQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Delay the execution of a callback.
     *
     * The delay is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be determined by which
     * timers expire first, but timers with the same expiration time MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param float $delay The amount of time, in seconds, to delay the execution for.
     * @param callable(string):void $callback The callback to delay. The `$watcherId` will be
     *     invalidated before the callback invocation.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function delay(float $delay, callable $callback): string
    {
        if ($delay < 0) {
            throw new \Error("Delay must be greater than or equal to zero");
        }

        $watcher = new TimerWatcher($this->nextId++, $delay, $callback, $this->now() + $delay);

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Repeatedly execute a callback.
     *
     * The interval between executions is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be
     * determined by which timers expire first, but timers with the same expiration time MAY be executed in any order.
     * The first execution is scheduled after the first interval period.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param float $interval The time interval, in seconds, to wait between executions.
     * @param callable(string):void $callback The callback to repeat.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function repeat(float $interval, callable $callback): string
    {
        if ($interval < 0) {
            throw new \Error("Interval must be greater than or equal to zero");
        }

        $watcher = new TimerWatcher($this->nextId++, $interval, $callback, $this->now() + $interval, true);

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * watcher when closing the resource locally. Drivers MAY choose to notify the user if there are watchers on invalid
     * resources, but are not required to, due to the high performance impact. Watchers on closed resources are
     * therefore undefined behavior.
     *
     * Multiple watchers on the same stream MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource):void $callback The callback to execute.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function onReadable(mixed $stream, callable $callback): string
    {
        $watcher = new StreamReadWatcher($this->nextId++, $callback, $stream);

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * watcher when closing the resource locally. Drivers MAY choose to notify the user if there are watchers on invalid
     * resources, but are not required to, due to the high performance impact. Watchers on closed resources are
     * therefore undefined behavior.
     *
     * Multiple watchers on the same stream MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource|object):void $callback The callback to execute.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function onWritable($stream, callable $callback): string
    {
        $watcher = new StreamWriteWatcher($this->nextId++, $callback, $stream);

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Execute a callback when a signal is received.
     *
     * Warning: Installing the same signal on different instances of this interface is deemed undefined behavior.
     * Implementations MAY try to detect this, if possible, but are not required to. This is due to technical
     * limitations of the signals being registered globally per process.
     *
     * Multiple watchers on the same signal MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param int   $signo The signal number to monitor.
     * @param callable(string, int):void $callback The callback to execute.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public function onSignal(int $signo, callable $callback): string
    {
        $watcher = new SignalWatcher($this->nextId++, $callback, $signo);

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Enable a watcher to be active starting in the next tick.
     *
     * Watchers MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right before
     * the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return string The watcher identifier.
     *
     * @throws InvalidWatcherError If the watcher identifier is invalid.
     */
    public function enable(string $watcherId): string
    {
        if (!isset($this->watchers[$watcherId])) {
            throw new InvalidWatcherError($watcherId, "Cannot enable an invalid watcher identifier: '{$watcherId}'");
        }

        $watcher = $this->watchers[$watcherId];

        if ($watcher->enabled) {
            return $watcherId; // Watcher already enabled.
        }

        $watcher->enabled = true;

        if ($watcher instanceof DeferWatcher) {
            $this->nextTickQueue[$watcher->id] = $watcher;
        } elseif ($watcher instanceof TimerWatcher) {
            $watcher->expiration = $this->now() + $watcher->interval;
            $this->enableQueue[$watcher->id] = $watcher;
        } else {
            $this->enableQueue[$watcher->id] = $watcher;
        }

        return $watcherId;
    }

    /**
     * Cancel a watcher.
     *
     * This will detach the event loop from all resources that are associated to the watcher. After this operation the
     * watcher is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid watcher.
     *
     * @param string $watcherId The watcher identifier.
     */
    public function cancel(string $watcherId): void
    {
        $this->disable($watcherId);
        unset($this->watchers[$watcherId]);
    }

    /**
     * Disable a watcher immediately.
     *
     * A watcher MUST be disabled immediately, e.g. if a defer watcher disables a later defer watcher, the second defer
     * watcher isn't executed in this tick.
     *
     * Disabling a watcher MUST NOT invalidate the watcher. Calling this function MUST NOT fail, even if passed an
     * invalid watcher.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return string The watcher identifier.
     */
    public function disable(string $watcherId): string
    {
        if (!isset($this->watchers[$watcherId])) {
            return $watcherId;
        }

        $watcher = $this->watchers[$watcherId];

        if (!$watcher->enabled) {
            return $watcherId; // Watcher already disabled.
        }

        $watcher->enabled = false;
        $id = $watcher->id;

        if ($watcher instanceof DeferWatcher) {
            if (isset($this->nextTickQueue[$id])) {
                // Watcher was only queued to be enabled.
                unset($this->nextTickQueue[$id]);
            } else {
                unset($this->deferQueue[$id]);
            }
        } else {
            if (isset($this->enableQueue[$id])) {
                // Watcher was only queued to be enabled.
                unset($this->enableQueue[$id]);
            } else {
                $this->deactivate($watcher);
            }
        }

        return $watcherId;
    }

    /**
     * Reference a watcher.
     *
     * This will keep the event loop alive whilst the watcher is still being monitored. Watchers have this state by
     * default.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return string The watcher identifier.
     *
     * @throws InvalidWatcherError If the watcher identifier is invalid.
     */
    public function reference(string $watcherId): string
    {
        if (!isset($this->watchers[$watcherId])) {
            throw new InvalidWatcherError($watcherId, "Cannot reference an invalid watcher identifier: '{$watcherId}'");
        }

        $this->watchers[$watcherId]->referenced = true;

        return $watcherId;
    }

    /**
     * Unreference a watcher.
     *
     * The event loop should exit the run method when only unreferenced watchers are still being monitored. Watchers
     * are all referenced by default.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return string The watcher identifier.
     */
    public function unreference(string $watcherId): string
    {
        if (!isset($this->watchers[$watcherId])) {
            return $watcherId;
        }

        $this->watchers[$watcherId]->referenced = false;

        return $watcherId;
    }

    /**
     * Set a callback to be executed when an error occurs.
     *
     * The callback receives the error as the first and only parameter. The return value of the callback gets ignored.
     * If it can't handle the error, it MUST throw the error. Errors thrown by the callback or during its invocation
     * MUST be thrown into the `run` loop and stop the driver.
     *
     * Subsequent calls to this method will overwrite the previous handler.
     *
     * @param ?(callable(\Throwable):void) $callback The callback to execute. `null` will clear the
     *     current handler.
     *
     * @return ?(callable(\Throwable):void) The previous handler, `null` if there was none.
     */
    public function setErrorHandler(callable $callback = null): ?callable
    {
        $previous = $this->errorHandler;
        $this->errorHandler = $callback;
        return $previous;
    }

    /**
     * Returns the same array of data as getInfo().
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return $this->getInfo();
        // @codeCoverageIgnoreEnd
    }

    /**
     * Retrieve an associative array of information about the event loop driver.
     *
     * The returned array MUST contain the following data describing the driver's currently registered watchers:
     *
     *     [
     *         "defer"            => ["enabled" => int, "disabled" => int],
     *         "delay"            => ["enabled" => int, "disabled" => int],
     *         "repeat"           => ["enabled" => int, "disabled" => int],
     *         "on_readable"      => ["enabled" => int, "disabled" => int],
     *         "on_writable"      => ["enabled" => int, "disabled" => int],
     *         "on_signal"        => ["enabled" => int, "disabled" => int],
     *         "enabled_watchers" => ["referenced" => int, "unreferenced" => int],
     *     ];
     *
     * Implementations MAY optionally add more information in the array but at minimum the above `key => value` format
     * MUST always be provided.
     *
     * @return array Statistics about the loop in the described format.
     */
    public function getInfo(): array
    {
        $watchers = [
            "referenced" => 0,
            "unreferenced" => 0,
        ];

        $defer = $delay = $repeat = $onReadable = $onWritable = $onSignal = [
            "enabled" => 0,
            "disabled" => 0,
        ];

        foreach ($this->watchers as $watcher) {
            if ($watcher instanceof StreamReadWatcher) {
                $array = &$onReadable;
            } elseif ($watcher instanceof StreamWriteWatcher) {
                $array = &$onWritable;
            } elseif ($watcher instanceof SignalWatcher) {
                $array = &$onSignal;
            } elseif ($watcher instanceof TimerWatcher) {
                if ($watcher->repeat) {
                    $array = &$repeat;
                } else {
                    $array = &$delay;
                }
            } elseif ($watcher instanceof DeferWatcher) {
                $array = &$defer;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown watcher type");
                // @codeCoverageIgnoreEnd
            }

            if ($watcher->enabled) {
                ++$array["enabled"];

                if ($watcher->referenced) {
                    ++$watchers["referenced"];
                } else {
                    ++$watchers["unreferenced"];
                }
            } else {
                ++$array["disabled"];
            }
        }

        return [
            "enabled_watchers" => $watchers,
            "defer" => $defer,
            "delay" => $delay,
            "repeat" => $repeat,
            "on_readable" => $onReadable,
            "on_writable" => $onWritable,
            "on_signal" => $onSignal,
        ];
    }

    /**
     * Activates (enables) all the given watchers.
     *
     * @param Watcher[] $watchers
     */
    abstract protected function activate(array $watchers): void;

    /**
     * Dispatches any pending read/write, timer, and signal events.
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * Deactivates (disables) the given watcher.
     */
    abstract protected function deactivate(Watcher $watcher): void;

    protected function invokeCallback(Watcher $watcher): void
    {
        if ($this->fiber->isRunning()) {
            $this->fiber = $this->createFiber();
        }

        try {
            $yielded = $this->fiber->resume($watcher);

            if ($yielded !== $watcher) {
                // Watcher callback suspended.
                $this->fiber = $this->createFiber();
            }
        } catch (\Throwable $exception) {
            $this->fiber = $this->createFiber();
            $this->error($exception);
        }

        if ($this->microQueue) {
            $this->invokeMicrotasks();
        }
    }

    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from a watcher callback.
     *
     * @throws \Throwable If no error handler has been set.
     */
    protected function error(\Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            throw $exception;
        }

        ($this->errorHandler)($exception);
    }

    /**
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    /**
     * @return bool True if no enabled and referenced watchers remain in the loop.
     */
    private function isEmpty(): bool
    {
        foreach ($this->watchers as $watcher) {
            if ($watcher->enabled && $watcher->referenced) {
                return false;
            }
        }

        return true;
    }

    /**
     * Executes a single tick of the event loop.
     */
    private function tick(): void
    {
        if (empty($this->deferQueue)) {
            $this->deferQueue = $this->nextTickQueue;
        } else {
            $this->deferQueue = \array_merge($this->deferQueue, $this->nextTickQueue);
        }
        $this->nextTickQueue = [];

        $this->activate($this->enableQueue);
        $this->enableQueue = [];

        foreach ($this->deferQueue as $watcher) {
            if (!isset($this->deferQueue[$watcher->id])) {
                continue; // Watcher disabled by another defer watcher.
            }

            unset($this->watchers[$watcher->id], $this->deferQueue[$watcher->id]);

            $this->invokeCallback($watcher);
        }

        /** @psalm-suppress RedundantCondition */
        $this->dispatch(
            empty($this->nextTickQueue)
            && empty($this->enableQueue)
            && $this->running
            && !$this->isEmpty()
        );
    }

    private function invokeMicrotasks(): void
    {
        while ($this->microQueue) {
            foreach ($this->microQueue as $id => [$callable, $args]) {
                try {
                    unset($this->microQueue[$id]);
                    $callable(...$args);
                } catch (\Throwable $exception) {
                    $this->error($exception);
                }
            }
        }
    }

    private function createFiber(): \Fiber
    {
        $fiber = new \Fiber(static function (): void {
            $watcher = null;
            while ($watcher = \Fiber::suspend($watcher)) {
                $result = match (true) {
                    $watcher instanceof StreamWatcher => ($watcher->callback)($watcher->id, $watcher->stream),
                    $watcher instanceof SignalWatcher => ($watcher->callback)($watcher->id, $watcher->signal),
                    default => ($watcher->callback)($watcher->id),
                };

                if ($result !== null) {
                    throw InvalidCallbackError::noVoid($watcher->id, $watcher->callback);
                }
            }
        });

        $fiber->start();
        return $fiber;
    }
}
