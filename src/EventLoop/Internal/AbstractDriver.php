<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Callbacks (enabled or new callbacks) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
 *
 * All registered callbacks MUST NOT be called from a file with strict types enabled (`declare(strict_types=1)`).
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Next callback identifier. */
    private string $nextId = "a";

    private \Fiber $callbackFiber;
    private \Fiber $queueFiber;
    private \Closure $errorCallback;

    /** @var Callback[] */
    private array $callbacks = [];

    /** @var Callback[] */
    private array $enableQueue = [];

    /** @var Callback[] */
    private array $deferQueue = [];

    /** @var Callback[] */
    private array $nextTickQueue = [];

    /** @var array<int, array<int, callable|list<mixed>>> */
    private array $microQueue = [];

    /** @var callable(\Throwable):void|null */
    private $errorHandler;

    /** @var callable|null */
    private $interrupt;

    private \Closure $interruptCallback;

    private bool $running = false;

    private bool $inFiber = false;

    private \stdClass $internalSuspensionMarker;

    public function __construct()
    {
        $this->internalSuspensionMarker = new \stdClass();
        $this->createCallbackFiber();
        $this->createQueueFiber();
        $this->createErrorCallback();
        /** @psalm-suppress InvalidArgument */
        $this->interruptCallback = \Closure::fromCallable([$this, 'setInterrupt']);
    }

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
    public function run(): void
    {
        if ($this->running) {
            throw new \Error("The loop was already running");
        }

        $this->running = true;
        $this->inFiber = \Fiber::getCurrent() !== null;

        try {
            while ($this->running) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                $this->invokeMicrotasks();

                if ($this->isEmpty()) {
                    return;
                }

                $this->tick();
            }
        } finally {
            $this->running = false;
            $this->inFiber = false;
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
     * Does NOT create an event callback, thus CAN NOT be marked as disabled or unreferenced.
     * Use {@see EventLoop::defer()} if you need these features.
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
     * The deferred callable MUST be executed before any other type of callback in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param callable(string):void $callback The callback to defer. The `$callbackId` will be
     *     invalidated before the callback call.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function defer(callable $callback): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $callback);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->nextTickQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
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
     * @param callable(string):void $callback The callback to delay. The `$callbackId` will be
     *     invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function delay(float $delay, callable $callback): string
    {
        if ($delay < 0) {
            throw new \Error("Delay must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $callback, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
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
     * @param callable(string):void $callback The callback to repeat.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function repeat(float $interval, callable $callback): string
    {
        if ($interval < 0) {
            throw new \Error("Interval must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $interval, $callback, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
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
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource):void $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function onReadable(mixed $stream, callable $callback): string
    {
        $streamCallback = new StreamReadableCallback($this->nextId++, $callback, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
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
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource|object):void $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function onWritable($stream, callable $callback): string
    {
        $streamCallback = new StreamWritableCallback($this->nextId++, $callback, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
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
     * @param int   $signo The signal number to monitor.
     * @param callable(string, int):void $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public function onSignal(int $signo, callable $callback): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $callback, $signo);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

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
    public function enable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->enabled) {
            return $callbackId; // Callback already enabled.
        }

        $callback->enabled = true;

        if ($callback instanceof DeferCallback) {
            $this->nextTickQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    /**
     * Cancel a callback.
     *
     * This will detach the event loop from all resources that are associated to the callback. After this operation the
     * callback is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     */
    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    /**
     * Disable a callback immediately.
     *
     * A callback MUST be disabled immediately, e.g. if a deferred callback disables another deferred callback,
     * the second deferred callback isn't executed in this tick.
     *
     * Disabling a callback MUST NOT invalidate the callback. Calling this function MUST NOT fail, even if passed an
     * invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // Callback already disabled.
        }

        $callback->enabled = false;
        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            if (isset($this->nextTickQueue[$id])) {
                // Callback was only queued to be enabled.
                unset($this->nextTickQueue[$id]);
            } else {
                unset($this->deferQueue[$id]);
            }
        } else {
            if (isset($this->enableQueue[$id])) {
                // Callback was only queued to be enabled.
                unset($this->enableQueue[$id]);
            } else {
                $this->deactivate($callback);
            }
        }

        return $callbackId;
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
    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $this->callbacks[$callbackId]->referenced = true;

        return $callbackId;
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
    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $this->callbacks[$callbackId]->referenced = false;

        return $callbackId;
    }

    public function createSuspension(\Fiber $scheduler): Suspension
    {
        return new DriverSuspension($this, $scheduler, $this->interruptCallback);
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
    public function getInfo(): array
    {
        $counts = [
            "referenced" => 0,
            "unreferenced" => 0,
        ];

        $defer = $delay = $repeat = $onReadable = $onWritable = $onSignal = [
            "enabled" => 0,
            "disabled" => 0,
        ];

        foreach ($this->callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                $array = &$onReadable;
            } elseif ($callback instanceof StreamWritableCallback) {
                $array = &$onWritable;
            } elseif ($callback instanceof SignalCallback) {
                $array = &$onSignal;
            } elseif ($callback instanceof TimerCallback) {
                if ($callback->repeat) {
                    $array = &$repeat;
                } else {
                    $array = &$delay;
                }
            } elseif ($callback instanceof DeferCallback) {
                $array = &$defer;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }

            if ($callback->enabled) {
                ++$array["enabled"];

                if ($callback->referenced) {
                    ++$counts["referenced"];
                } else {
                    ++$counts["unreferenced"];
                }
            } else {
                ++$array["disabled"];
            }
        }

        return [
            "enabled_watchers" => $counts,
            "defer" => $defer,
            "delay" => $delay,
            "repeat" => $repeat,
            "on_readable" => $onReadable,
            "on_writable" => $onWritable,
            "on_signal" => $onSignal,
        ];
    }

    /**
     * Activates (enables) all the given callbacks.
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * Dispatches any pending read/write, timer, and signal events.
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * Deactivates (disables) the given callback.
     */
    abstract protected function deactivate(Callback $callback): void;

    protected function invokeCallback(Callback $callback): void
    {
        if ($this->callbackFiber->isRunning()) {
            $this->createCallbackFiber();
        }

        try {
            $yielded = $this->callbackFiber->resume($callback);
            if ($yielded !== $this->internalSuspensionMarker) {
                // Callback suspended.
                $this->createCallbackFiber();
            }
        } catch (\Throwable $exception) {
            $this->createCallbackFiber();
            $this->error($exception);
        }

        if ($this->interrupt) {
            $this->invokeInterrupt();
        }

        if ($this->microQueue) {
            $this->invokeMicrotasks();
        }
    }

    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from an event callback.
     *
     * @throws \Throwable If no error handler has been set.
     */
    protected function error(\Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            $this->setInterrupt(static fn () => throw $exception);
            return;
        }

        $fiber = new \Fiber($this->errorCallback);
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    /**
     * @return bool True if no enabled and referenced callbacks remain in the loop.
     */
    private function isEmpty(): bool
    {
        foreach ($this->callbacks as $callback) {
            if ($callback->enabled && $callback->referenced) {
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

        foreach ($this->deferQueue as $callback) {
            if (!isset($this->deferQueue[$callback->id])) {
                continue; // Callback disabled by another deferred callback.
            }

            unset($this->callbacks[$callback->id], $this->deferQueue[$callback->id]);

            $this->invokeCallback($callback);
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
            foreach ($this->microQueue as $id => $queueEntry) {
                if ($this->queueFiber->isRunning()) {
                    $this->createQueueFiber();
                }

                try {
                    unset($this->microQueue[$id]);

                    $yielded = $this->queueFiber->resume($queueEntry);
                    if ($yielded !== $this->internalSuspensionMarker) {
                        $this->createQueueFiber();
                    }
                } catch (\Throwable $exception) {
                    $this->createQueueFiber();
                    $this->error($exception);
                }

                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }
            }
        }
    }

    private function setInterrupt(callable $interrupt): void
    {
        \assert($this->interrupt === null);
        $this->interrupt = $interrupt;
    }

    private function invokeInterrupt(): void
    {
        \assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        if (!$this->inFiber) {
            $interrupt();
            throw new \Error('Interrupt must throw if not executing in a fiber');
        }

        \Fiber::suspend($interrupt);
    }

    private function createCallbackFiber(): void
    {
        $suspensionMarker = $this->internalSuspensionMarker;

        $this->callbackFiber = new \Fiber(static function () use ($suspensionMarker): void {
            while ($callback = \Fiber::suspend($suspensionMarker)) {
                $result = match (true) {
                    $callback instanceof StreamCallback => ($callback->callback)($callback->id, $callback->stream),
                    $callback instanceof SignalCallback => ($callback->callback)($callback->id, $callback->signal),
                    default => ($callback->callback)($callback->id),
                };

                if ($result !== null) {
                    throw InvalidCallbackError::nonNullReturn($callback->id, $callback->callback);
                }

                unset($callback);
            }
        });

        $this->callbackFiber->start();
    }

    private function createQueueFiber(): void
    {
        $suspensionMarker = $this->internalSuspensionMarker;

        $this->queueFiber = new \Fiber(static function () use ($suspensionMarker): void {
            while ([$callback, $args] = \Fiber::suspend($suspensionMarker)) {
                $callback(...$args);

                unset($callback, $args);
            }
        });

        $this->queueFiber->start();
    }

    private function createErrorCallback(): void
    {
        $this->errorCallback = function (callable $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn () => throw $exception;
            }
        };
    }
}
