<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UncaughtThrowable;

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
    private static function checkFiberSupport(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            if (\PHP_VERSION_ID < 80000) {
                throw new \Error(
                    "revolt/event-loop requires fibers to be available. " .
                    "You're currently running PHP " . \PHP_VERSION . " without fiber support. " .
                    "Please upgrade to PHP 8.1 or upgrade to PHP 8.0 and install ext-fiber from https://github.com/amphp/ext-fiber."
                );
            }

            if (\PHP_VERSION_ID < 80100) {
                throw new \Error(
                    "revolt/event-loop requires fibers to be available. " .
                    "You're currently running PHP " . \PHP_VERSION . " without fiber support. " .
                    "Please upgrade to PHP 8.1 or install ext-fiber from https://github.com/amphp/ext-fiber."
                );
            }

            throw new \Error(
                "revolt/event-loop requires PHP 8.1 or ext-fiber. You are currently running PHP " . \PHP_VERSION . "."
            );
        }
    }

    /** @var string Next callback identifier. */
    private string $nextId = "a";

    private \Fiber $fiber;

    private \Fiber $callbackFiber;
    private \Closure $errorCallback;

    /** @var DriverCallback[] */
    private array $callbacks = [];

    /** @var DriverCallback[] */
    private array $enableQueue = [];

    /** @var DriverCallback[] */
    private array $enableDeferQueue = [];

    /** @var null|\Closure(\Throwable) */
    private ?\Closure $errorHandler = null;
    private ?\Closure $interrupt = null;

    private \Closure $interruptCallback;
    private \Closure $queueCallback;
    private \Closure $runCallback;

    private \stdClass $internalSuspensionMarker;

    /** @var \SplQueue<array{callable, array}> */
    private \SplQueue $microtaskQueue;

    /** @var \SplQueue<DriverCallback> */
    private \SplQueue $callbackQueue;

    private bool $idle = false;
    private bool $stopped = false;

    public function __construct()
    {
        self::checkFiberSupport();

        $this->internalSuspensionMarker = new \stdClass();
        $this->microtaskQueue = new \SplQueue();
        $this->callbackQueue = new \SplQueue();

        $this->createFiber();
        $this->createCallbackFiber();
        $this->createErrorCallback();

        /** @psalm-suppress InvalidArgument */
        $this->interruptCallback = \Closure::fromCallable([$this, 'setInterrupt']);
        $this->queueCallback = \Closure::fromCallable([$this, 'queue']);
        $this->runCallback = function () {
            if ($this->fiber->isTerminated()) {
                $this->createFiber();
            }

            return $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        };
    }

    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new \Error("The event loop is already running");
        }

        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        if ($this->fiber->isTerminated()) {
            $this->createFiber();
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        $lambda = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();

        if ($lambda) {
            $lambda();

            throw new \Error('Interrupt from event loop must throw an exception');
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isRunning(): bool
    {
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->microtaskQueue->enqueue([$closure, $args]);
    }

    public function defer(\Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
    }

    public function delay(float $delay, \Closure $closure): string
    {
        if ($delay < 0) {
            throw new \Error("Delay must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    public function repeat(float $interval, \Closure $closure): string
    {
        if ($interval < 0) {
            throw new \Error("Interval must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $interval, $closure, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $streamCallback = new StreamReadableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    public function onWritable($stream, \Closure $closure): string
    {
        $streamCallback = new StreamWritableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    public function onSignal(int $signal, \Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $closure, $signal);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

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
            $this->enableDeferQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

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
        $callback->invokable = false;
        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            // Callback was only queued to be enabled.
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // Callback was only queued to be enabled.
            unset($this->enableQueue[$id]);
        } else {
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $this->callbacks[$callbackId]->referenced = true;

        return $callbackId;
    }

    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $this->callbacks[$callbackId]->referenced = false;

        return $callbackId;
    }

    public function createSuspension(): Suspension
    {
        // User callbacks are always executed outside the event loop fiber, so this should always be false.
        \assert(\Fiber::getCurrent() !== $this->fiber);

        return new DriverSuspension($this->runCallback, $this->queueCallback, $this->interruptCallback);
    }

    public function setErrorHandler(?\Closure $errorHandler): ?callable
    {
        $previous = $this->errorHandler;
        $this->errorHandler = $errorHandler;
        return $previous;
    }

    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return $this->getInfo();
        // @codeCoverageIgnoreEnd
    }

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
    abstract protected function deactivate(DriverCallback $callback): void;

    final protected function enqueueCallback(DriverCallback $callback): void
    {
        $this->callbackQueue->enqueue($callback);
        $this->idle = false;
    }

    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from an event callback.
     */
    final protected function error(\Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            $this->setInterrupt(static fn () => throw UncaughtThrowable::throwingCallback($exception));
            return;
        }

        $fiber = new \Fiber($this->errorCallback);

        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    private function invokeMicrotasks(): void
    {
        while (!$this->microtaskQueue->isEmpty()) {
            [$callback, $args] = $this->microtaskQueue->dequeue();

            try {
                $callback(...$args);
            } catch (\Throwable $exception) {
                $this->error($exception);
            }

            unset($callback, $args);

            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

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
    private function tick(bool $previousIdle): void
    {
        $this->activate($this->enableQueue);

        foreach ($this->enableQueue as $callback) {
            $callback->invokable = true;
        }

        $this->enableQueue = [];

        foreach ($this->enableDeferQueue as $callback) {
            $callback->invokable = true;
            $this->enqueueCallback($callback);
        }

        $this->enableDeferQueue = [];

        $blocking = $previousIdle
            && !$this->stopped
            && !$this->isEmpty();

        if ($blocking) {
            $this->invokeCallbacks();

            /** @psalm-suppress TypeDoesNotContainType */
            if (!empty($this->enableDeferQueue) || !empty($this->enableQueue)) {
                $blocking = false;
            }
        }

        /** @psalm-suppress RedundantCondition */
        $this->dispatch($blocking);
    }

    private function invokeCallbacks(): void
    {
        while (!$this->microtaskQueue->isEmpty() || !$this->callbackQueue->isEmpty()) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $yielded = $this->callbackFiber->isStarted()
                ? $this->callbackFiber->resume()
                : $this->callbackFiber->start();

            if ($yielded !== $this->internalSuspensionMarker) {
                $this->createCallbackFiber();
            }

            if ($this->interrupt) {
                $this->invokeInterrupt();
            }
        }
    }

    private function setInterrupt(\Closure $interrupt): void
    {
        \assert($this->interrupt === null);
        $this->interrupt = $interrupt;
    }

    private function invokeInterrupt(): void
    {
        \assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** @noinspection PhpUnhandledExceptionInspection */
        \Fiber::suspend($interrupt);
    }

    private function createFiber(): void
    {
        $this->fiber = new \Fiber(function () {
            $this->stopped = false;

            // Invoke microtasks if we have some
            $this->invokeCallbacks();

            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                if ($this->isEmpty()) {
                    return;
                }

                $previousIdle = $this->idle;
                $this->idle = true;

                $this->tick($previousIdle);
                $this->invokeCallbacks();
            }
        });
    }

    private function createCallbackFiber(): void
    {
        $this->callbackFiber = new \Fiber(function (): void {
            do {
                $this->invokeMicrotasks();

                while (!$this->callbackQueue->isEmpty()) {
                    /** @var DriverCallback $callback */
                    $callback = $this->callbackQueue->dequeue();

                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);

                        continue;
                    }

                    if ($callback instanceof DeferCallback) {
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof TimerCallback) {
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            // Disable and re-enable, so it's not executed repeatedly in the same tick
                            // See https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }

                    try {
                        $result = match (true) {
                            $callback instanceof StreamCallback => ($callback->closure)(
                                $callback->id,
                                $callback->stream
                            ),
                            $callback instanceof SignalCallback => ($callback->closure)(
                                $callback->id,
                                $callback->signal
                            ),
                            default => ($callback->closure)($callback->id),
                        };

                        if ($result !== null) {
                            throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
                        }
                    } catch (\Throwable $exception) {
                        $this->error($exception);
                    }

                    unset($callback);

                    if ($this->interrupt) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        \Fiber::suspend($this->internalSuspensionMarker);
                    }

                    $this->invokeMicrotasks();
                }

                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            } while (true);
        });
    }

    private function createErrorCallback(): void
    {
        $this->errorCallback = function (\Closure $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->setInterrupt(static fn () => throw UncaughtThrowable::throwingErrorHandler($exception));
            }
        };
    }
}
