<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;

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

            if (\PHP_VERSION_ID >= 80000 && \PHP_VERSION_ID < 80100) {
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

    private ?\Closure $errorHandler = null;
    private ?\Closure $interrupt = null;

    private \Closure $interruptCallback;
    private \Closure $queueCallback;
    private \Closure $runCallback;

    private \stdClass $internalSuspensionMarker;

    private bool $stopped = false;

    public function __construct()
    {
        self::checkFiberSupport();

        $this->internalSuspensionMarker = new \stdClass();

        $this->createFiber();
        $this->createCallbackFiber();
        $this->createQueueFiber();
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
        $this->microQueue[] = [$closure, $args];
    }

    public function defer(\Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->nextTickQueue[$deferCallback->id] = $deferCallback;

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
            $this->nextTickQueue[$callback->id] = $callback;
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
            && !$this->stopped
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

        \Fiber::suspend($interrupt);
    }

    private function createFiber(): void
    {
        $this->fiber = new \Fiber(function () {
            $this->stopped = false;

            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                $this->invokeMicrotasks();

                if ($this->isEmpty()) {
                    return;
                }

                $this->tick();
            }
        });
    }

    private function createCallbackFiber(): void
    {
        $suspensionMarker = $this->internalSuspensionMarker;

        $this->callbackFiber = new \Fiber(static function () use ($suspensionMarker): void {
            while ($callback = \Fiber::suspend($suspensionMarker)) {
                $result = match (true) {
                    $callback instanceof StreamCallback => ($callback->closure)($callback->id, $callback->stream),
                    $callback instanceof SignalCallback => ($callback->closure)($callback->id, $callback->signal),
                    default => ($callback->closure)($callback->id),
                };

                if ($result !== null) {
                    throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
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
        $this->errorCallback = function (\Closure $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn () => throw $exception;
            }
        };
    }
}
