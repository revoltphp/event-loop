<?php

namespace Revolt\EventLoop;

interface Driver
{
    /**
     * Run the event loop.
     *
     * One iteration of the loop is called one "tick". A tick covers the following steps:
     *
     *  1. Activate callbacks created / enabled in the last tick / before `run()`.
     *  2. Execute all enabled deferred callbacks.
     *  3. Execute all due timer, pending signal and actionable stream callbacks, each only once per tick.
     *
     * The loop MUST continue to run until it is either stopped explicitly, no referenced callbacks exist anymore, or an
     * exception is thrown that cannot be handled. Exceptions that cannot be handled are exceptions thrown from an
     * error handler or exceptions that would be passed to an error handler but none exists to handle them.
     *
     * @throw \Error Thrown if the event loop is already running.
     */
    public function run(): void;

    /**
     * Stop the event loop.
     *
     * When an event loop is stopped, it continues with its current tick and exits the loop afterwards. Multiple calls
     * to stop MUST be ignored and MUST NOT raise an exception.
     */
    public function stop(): void;

    /**
     * Create an object used to suspend and resume execution, either within a fiber or from {main}.
     *
     * @return Suspension
     */
    public function createSuspension(): Suspension;

    /**
     * @return bool True if the event loop is running, false if it is stopped.
     */
    public function isRunning(): bool;

    /**
     * Queue a microtask.
     *
     * The queued callable MUST be executed immediately once the event loop gains control. Order of queueing MUST be
     * preserved when executing the callbacks. Recursive scheduling can thus result in infinite loops, use with care.
     *
     * Does NOT create an event callback, thus CAN NOT be marked as disabled or unreferenced.
     * Use {@see EventLoop::defer()} if you need these features.
     *
     * @param callable $callback The callback to queue.
     * @param mixed    ...$args The callback arguments.
     */
    public function queue(callable $callback, mixed ...$args): void;

    /**
     * Defer the execution of a callback.
     *
     * The deferred callable MUST be executed before any other type of callback in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param callable(string):void $callback The callback to defer. The `$callbackId` will be
     *                    invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function defer(callable $callback): string;

    /**
     * Delay the execution of a callback.
     *
     * The delay is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be determined by which
     * timers expire first, but timers with the same expiration time MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param float   $delay The amount of time, in seconds, to delay the execution for.
     * @param callable(string):void $callback The callback to delay. The `$callbackId` will be
     *                     invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function delay(float $delay, callable $callback): string;

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
     * @param float   $interval The time interval, in seconds, to wait between executions.
     * @param callable(string):void $callback The callback to repeat.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function repeat(float $interval, callable $callback): string;

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
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource|object):void $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function onReadable(mixed $stream, callable $callback): string;

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
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource|object):void $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function onWritable(mixed $stream, callable $callback): string;

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
     * @param int   $signo The signal number to monitor.
     * @param callable(string, int):void $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public function onSignal(int $signo, callable $callback): string;

    /**
     * Enable a callback to be active starting in the next tick.
     *
     * Callbacks MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right before
     * the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public function enable(string $callbackId): string;

    /**
     * Cancel a callback.
     *
     * This will detach the event loop from all resources that are associated to the callback. After this operation the
     * callback is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     */
    public function cancel(string $callbackId): void;

    /**
     * Disable a callback immediately.
     *
     * A callback MUST be disabled immediately, e.g. if a deferred callback disables a later deferred callback,
     * the second deferred callback isn't executed in this tick.
     *
     * Disabling a callback MUST NOT invalidate the callback. Calling this function MUST NOT fail, even if passed an
     * invalid callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public function disable(string $callbackId): string;

    /**
     * Reference a callback.
     *
     * This will keep the event loop alive whilst the callback is still being monitored. Callbacks have this state by
     * default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public function reference(string $callbackId): string;

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
    public function unreference(string $callbackId): string;

    /**
     * Set a callback to be executed when an error occurs.
     *
     * The callback receives the error as the first and only parameter. The return value of the callback gets ignored.
     * If it can't handle the error, it MUST throw the error. Errors thrown by the callback or during its invocation
     * MUST be thrown into the `run` loop and stop the driver.
     *
     * Subsequent calls to this method will overwrite the previous handler.
     *
     * @param (callable(\Throwable):void)|null $callback The callback to execute. `null` will clear the
     *     current handler.
     *
     * @return (callable(\Throwable):void)|null The previous handler, `null` if there was none.
     */
    public function setErrorHandler(callable $callback = null): ?callable;

    /**
     * Get the underlying loop handle.
     *
     * Example: the `uv_loop` resource for `libuv` or the `EvLoop` object for `libev` or `null` for a stream_select
     * driver.
     *
     * Note: This function is *not* exposed in the `Loop` class. Users shall access it directly on the respective loop
     * instance.
     *
     * @return null|object|resource The loop handle the event loop operates on. `null` if there is none.
     */
    public function getHandle(): mixed;

    /**
     * Returns the same array of data as getInfo().
     *
     * @return array
     */
    public function __debugInfo(): array;

    /**
     * Retrieve an associative array of information about the event loop driver.
     *
     * The returned array MUST contain the following data describing the driver's currently registered callbacks:
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
    public function getInfo(): array;
}
