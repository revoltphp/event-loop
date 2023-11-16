<?php

declare(strict_types=1);

namespace Revolt;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\InvalidCallbackError;
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

    /**
     * Sets the driver to be used as the event loop.
     */
    public static function setDriver(Driver $driver): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (isset(self::$driver) && self::$driver->isRunning()) {
            throw new \Error("Can't swap the event loop driver while the driver is running");
        }

        try {
            /** @psalm-suppress InternalClass */
            self::$driver = new class () extends AbstractDriver {
                protected function activate(array $callbacks): void
                {
                    throw new \Error("Can't activate callback during garbage collection.");
                }

                protected function dispatch(bool $blocking): void
                {
                    throw new \Error("Can't dispatch during garbage collection.");
                }

                protected function deactivate(DriverCallback $callback): void
                {
                    // do nothing
                }

                public function getHandle(): mixed
                {
                    return null;
                }

                protected function now(): float
                {
                    return (float) \hrtime(true) / 1_000_000_000;
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
     * The queued callback MUST be executed immediately once the event loop gains control. Order of queueing MUST be
     * preserved when executing the callbacks. Recursive scheduling can thus result in infinite loops, use with care.
     *
     * Does NOT create an event callback, thus CAN NOT be marked as disabled or unreferenced.
     * Use {@see EventLoop::defer()} if you need these features.
     *
     * @param \Closure(...):void $closure The callback to queue.
     * @param mixed ...$args The callback arguments.
     */
    public static function queue(\Closure $closure, mixed ...$args): void
    {
        self::getDriver()->queue($closure, ...$args);
    }

    /**
     * Defer the execution of a callback.
     *
     * The deferred callback MUST be executed before any other type of callback in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Deferred callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param \Closure(string):void $closure The callback to defer. The `$callbackId` will be
     *     invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function defer(\Closure $closure): string
    {
        return self::getDriver()->defer($closure);
    }

    /**
     * Delay the execution of a callback.
     *
     * The delay is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be determined by which
     * timers expire first, but timers with the same expiration time MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param float $delay The amount of time, in seconds, to delay the execution for.
     * @param \Closure(string):void $closure The callback to delay. The `$callbackId` will be invalidated
     *     before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function delay(float $delay, \Closure $closure): string
    {
        return self::getDriver()->delay($delay, $closure);
    }

    /**
     * Repeatedly execute a callback.
     *
     * The interval between executions is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be
     * determined by which timers expire first, but timers with the same expiration time MAY be executed in any order.
     * The first execution is scheduled after the first interval period.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param float $interval The time interval, in seconds, to wait between executions.
     * @param \Closure(string):void $closure The callback to repeat.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function repeat(float $interval, \Closure $closure): string
    {
        return self::getDriver()->repeat($interval, $closure);
    }

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * callback when closing the resource locally. Drivers MAY choose to notify the user if there are callbacks on
     * invalid resources, but are not required to, due to the high performance impact. Callbacks on closed resources are
     * therefore undefined behavior.
     *
     * Multiple callbacks on the same stream MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param resource $stream The stream to monitor.
     * @param \Closure(string, resource):void $closure The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function onReadable(mixed $stream, \Closure $closure): string
    {
        return self::getDriver()->onReadable($stream, $closure);
    }

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * callback when closing the resource locally. Drivers MAY choose to notify the user if there are callbacks on
     * invalid resources, but are not required to, due to the high performance impact. Callbacks on closed resources are
     * therefore undefined behavior.
     *
     * Multiple callbacks on the same stream MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param resource $stream The stream to monitor.
     * @param \Closure(string, resource):void $closure The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function onWritable(mixed $stream, \Closure $closure): string
    {
        return self::getDriver()->onWritable($stream, $closure);
    }

    /**
     * Execute a callback when a signal is received.
     *
     * Warning: Installing the same signal on different instances of this interface is deemed undefined behavior.
     * Implementations MAY try to detect this, if possible, but are not required to. This is due to technical
     * limitations of the signals being registered globally per process.
     *
     * Multiple callbacks on the same signal MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param int $signal The signal number to monitor.
     * @param \Closure(string, int):void $closure The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public static function onSignal(int $signal, \Closure $closure): string
    {
        return self::getDriver()->onSignal($signal, $closure);
    }

    /**
     * Enable a callback to be active starting in the next tick.
     *
     * Callbacks MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right
     * before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public static function enable(string $callbackId): string
    {
        return self::getDriver()->enable($callbackId);
    }

    /**
     * Disable a callback immediately.
     *
     * A callback MUST be disabled immediately, e.g. if a deferred callback disables another deferred callback,
     * the second deferred callback isn't executed in this tick.
     *
     * Disabling a callback MUST NOT invalidate the callback. Calling this function MUST NOT fail, even if passed an
     * invalid callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public static function disable(string $callbackId): string
    {
        return self::getDriver()->disable($callbackId);
    }

    /**
     * Cancel a callback.
     *
     * This will detach the event loop from all resources that are associated to the callback. After this operation the
     * callback is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     */
    public static function cancel(string $callbackId): void
    {
        self::getDriver()->cancel($callbackId);
    }

    /**
     * Reference a callback.
     *
     * This will keep the event loop alive whilst the event is still being monitored. Callbacks have this state by
     * default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public static function reference(string $callbackId): string
    {
        return self::getDriver()->reference($callbackId);
    }

    /**
     * Unreference a callback.
     *
     * The event loop should exit the run method when only unreferenced callbacks are still being monitored. Callbacks
     * are all referenced by default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public static function unreference(string $callbackId): string
    {
        return self::getDriver()->unreference($callbackId);
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
     * @param null|\Closure(\Throwable):void $errorHandler The callback to execute. `null` will clear the current handler.
     */
    public static function setErrorHandler(?\Closure $errorHandler): void
    {
        self::getDriver()->setErrorHandler($errorHandler);
    }

    /**
     * Gets the error handler closure or {@code null} if none is set.
     *
     * @return null|\Closure(\Throwable):void The previous handler, `null` if there was none.
     */
    public static function getErrorHandler(): ?\Closure
    {
        return self::getDriver()->getErrorHandler();
    }

    /**
     * Returns all registered non-cancelled callback identifiers.
     *
     * @return string[] Callback identifiers.
     */
    public static function getIdentifiers(): array
    {
        return self::getDriver()->getIdentifiers();
    }

    /**
     * Returns the type of the callback identified by the given callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return CallbackType The callback type.
     */
    public static function getType(string $callbackId): CallbackType
    {
        return self::getDriver()->getType($callbackId);
    }

    /**
     * Returns whether the callback identified by the given callback identifier is currently enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return bool `true` if the callback is currently enabled, otherwise `false`.
     */
    public static function isEnabled(string $callbackId): bool
    {
        return self::getDriver()->isEnabled($callbackId);
    }

    /**
     * Returns whether the callback identified by the given callback identifier is currently referenced.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return bool `true` if the callback is currently referenced, otherwise `false`.
     */
    public static function isReferenced(string $callbackId): bool
    {
        return self::getDriver()->isReferenced($callbackId);
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
     * Returns an object used to suspend and resume execution of the current fiber or {main}.
     *
     * Calls from the same fiber will return the same suspension object.
     *
     * @return Suspension
     */
    public static function getSuspension(): Suspension
    {
        return self::getDriver()->getSuspension();
    }

    /**
     * Run the event loop.
     *
     * This function may only be called from {main}, that is, not within a fiber.
     *
     * Libraries should use the {@link Suspension} API instead of calling this method.
     *
     * This method will not return until the event loop does not contain any pending, referenced callbacks anymore.
     */
    public static function run(): void
    {
        self::getDriver()->run();
    }

    /**
     * Disable construction as this is a static class.
     */
    private function __construct()
    {
        // intentionally left blank
    }
}
