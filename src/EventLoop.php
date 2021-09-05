<?php

namespace Revolt;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\Watcher;
use Revolt\EventLoop\InvalidWatcherError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * Accessor to allow global access to the event loop.
 *
 * @see Driver
 */
final class EventLoop
{
    private static Driver $driver;
    private static ?\Fiber $fiber;

    /**
     * Sets the driver to be used as the event loop.
     */
    public static function setDriver(Driver $driver): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (isset(self::$driver) && self::$driver->isRunning()) {
            throw new \Error("Can't swap the event loop driver while the driver is running");
        }

        self::$fiber = null;

        try {
            /** @psalm-suppress InternalClass */
            self::$driver = new class () extends AbstractDriver {
                protected function activate(array $watchers): void
                {
                    throw new \Error("Can't activate watcher during garbage collection.");
                }

                protected function dispatch(bool $blocking): void
                {
                    throw new \Error("Can't dispatch during garbage collection.");
                }

                protected function deactivate(Watcher $watcher): void
                {
                    // do nothing
                }

                public function getHandle(): mixed
                {
                    return null;
                }

                protected function now(): float
                {
                    return now();
                }
            };

            \gc_collect_cycles();
        } finally {
            self::$driver = $driver;
        }
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
     * @param mixed    ...$args The callback arguments.
     */
    public static function queue(callable $callback, mixed ...$args): void
    {
        self::getDriver()->queue($callback, ...$args);
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
     * @param callable(string) $callback The callback to defer. The `$watcherId` will be
     *     invalidated before the callback invocation.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public static function defer(callable $callback): string
    {
        return self::getDriver()->defer($callback);
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
     * @param float   $delay The amount of time, in seconds, to delay the execution for.
     * @param callable(string) $callback The callback to delay. The `$watcherId` will be invalidated before
     *     the callback invocation.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public static function delay(float $delay, callable $callback): string
    {
        return self::getDriver()->delay($delay, $callback);
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
     * @param float   $interval The time interval, in seconds, to wait between executions.
     * @param callable(string) $callback The callback to repeat.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public static function repeat(float $interval, callable $callback): string
    {
        return self::getDriver()->repeat($interval, $callback);
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
     * @param callable(string, resource|object) $callback The callback to execute.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public static function onReadable(mixed $stream, callable $callback): string
    {
        return self::getDriver()->onReadable($stream, $callback);
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
     * @param callable(string, resource|object) $callback The callback to execute.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public static function onWritable(mixed $stream, callable $callback): string
    {
        return self::getDriver()->onWritable($stream, $callback);
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
     * @param callable(string, int) $callback The callback to execute.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public static function onSignal(int $signo, callable $callback): string
    {
        return self::getDriver()->onSignal($signo, $callback);
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
    public static function enable(string $watcherId): string
    {
        return self::getDriver()->enable($watcherId);
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
    public static function disable(string $watcherId): string
    {
        return self::getDriver()->disable($watcherId);
    }

    /**
     * Cancel a watcher.
     *
     * This will detach the event loop from all resources that are associated to the watcher. After this operation the
     * watcher is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid watcher.
     *
     * @param string $watcherId The watcher identifier.
     */
    public static function cancel(string $watcherId): void
    {
        self::getDriver()->cancel($watcherId);
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
    public static function reference(string $watcherId): string
    {
        return self::getDriver()->reference($watcherId);
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
    public static function unreference(string $watcherId): string
    {
        return self::getDriver()->unreference($watcherId);
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
     * @param callable(\Throwable)|null $callback The callback to execute. `null` will clear the
     *     current handler.
     *
     * @return callable(\Throwable)|null The previous handler, `null` if there was none.
     */
    public static function setErrorHandler(callable $callback = null): ?callable
    {
        return self::getDriver()->setErrorHandler($callback);
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
     *         "running"          => bool
     *     ];
     *
     * Implementations MAY optionally add more information in the array but at minimum the above `key => value` format
     * MUST always be provided.
     *
     * @return array Statistics about the loop in the described format.
     */
    public static function getInfo(): array
    {
        return self::getDriver()->getInfo();
    }

    /**
     * Retrieve the event loop driver that is in scope.
     *
     * @return Driver
     */
    public static function getDriver(): Driver
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (!isset(self::$driver)) {
            self::setDriver((new DriverFactory())->create());
        }

        return self::$driver;
    }

    /**
     * Create an object used to suspend and resume execution, either within a fiber or from {main}.
     *
     * @return Suspension
     */
    public static function createSuspension(): Suspension
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!self::$fiber || self::$fiber->isTerminated()) {
            if (!\class_exists(\Fiber::class, false)) {
                throw new \Error("Fibers required to create loop suspensions");
            }

            self::$fiber = self::createFiber();
        }

        return new Suspension(self::getDriver(), self::$fiber);
    }

    /**
     * Run the event loop. This function may only be called from {main}, that is, not within a fiber.
     *
     * This method will not return until the event loop contains no pending, referenced watchers.
     */
    public static function run(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            if (self::getDriver()->isRunning()) {
                throw new \Error("The loop is already running");
            }

            self::getDriver()->run();
            return;
        }

        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        if (!self::$fiber || self::$fiber->isTerminated()) {
            self::$fiber = self::createFiber();
        }

        if (self::$fiber->isStarted()) {
            self::$fiber->resume();
        } else {
            self::$fiber->start();
        }
    }

    /**
     * Creates a fiber to run the active driver instance using {@see Driver::run()}.
     *
     * @return \Fiber Fiber used to run the event loop.
     */
    private static function createFiber(): \Fiber
    {
        return new \Fiber([self::getDriver(), 'run']);
    }

    /**
     * Disable construction as this is a static class.
     */
    private function __construct()
    {
        // intentionally left blank
    }
}
