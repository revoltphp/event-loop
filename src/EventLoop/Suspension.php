<?php

namespace Revolt\EventLoop;

use Revolt\EventLoop;

/**
 * Should be used to run and suspend the event loop instead of directly interacting with fibers.
 *
 * **Example**
 *
 * ```php
 * $suspension = EventLoop::createSuspension();
 *
 * $promise->then(fn ($value) => $suspension->resume($value), fn ($throwable) => $suspension->throw($throwable));
 *
 * $suspension->suspend();
 * ```
 */
final class Suspension
{
    /** @var string Next listener ID. */
    private static string $nextId = 'a';

    /** @var Listener[] */
    private static array $listeners = [];

    private static bool $invokingListeners = false;

    private ?\Fiber $fiber;
    private \Fiber $scheduler;
    private Driver $driver;
    private bool $pending = false;

    /**
     * Use {@see EventLoop::createSuspension()} to create Suspensions.
     *
     * @param Driver $driver
     * @param \Fiber $scheduler
     *
     * @internal
     */
    public function __construct(Driver $driver, \Fiber $scheduler)
    {
        $this->driver = $driver;
        $this->fiber = \Fiber::getCurrent();

        if ($this->fiber === $scheduler) {
            throw new \Error(\sprintf(
                'Cannot call %s() within a scheduler microtask (%s::queue() callback)',
                __METHOD__,
                EventLoop::class,
            ));
        }

        $this->scheduler = $scheduler;
    }

    public function throw(\Throwable $throwable): void
    {
        if (!$this->pending) {
            throw new \Error('Must call throw() before calling resume()');
        }

        if (self::$invokingListeners) {
            throw new \Error('Cannot call throw() within a suspension listener');
        }

        $this->pending = false;

        if ($this->fiber) {
            $this->driver->queue([$this->fiber, 'throw'], $throwable);
        } else {
            // Suspend event loop fiber to {main}.
            $this->driver->queue([\Fiber::class, 'suspend'], static fn () => throw $throwable);
        }
    }

    public function resume(mixed $value): void
    {
        if (!$this->pending) {
            throw new \Error('Must call suspend() before calling resume()');
        }

        if (self::$invokingListeners) {
            throw new \Error('Cannot call throw() within a suspension listener');
        }

        $this->pending = false;

        if ($this->fiber) {
            $this->driver->queue([$this->fiber, 'resume'], $value);
        } else {
            // Suspend event loop fiber to {main}.
            $this->driver->queue([\Fiber::class, 'suspend'], static fn () => $value);
        }
    }

    public function suspend(): mixed
    {
        if ($this->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again');
        }

        if ($this->fiber !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber');
        }

        if (self::$invokingListeners) {
            throw new \Error('Cannot call suspend() within a suspension listener');
        }

        $this->pending = true;

        if (!empty(self::$listeners)) {
            $this->invokeListeners('onSuspend');
        }

        try {
            // Awaiting from within a fiber.
            if ($this->fiber) {
                return \Fiber::suspend();
            }

            // Awaiting from {main}.
            $lambda = $this->scheduler->isStarted() ? $this->scheduler->resume() : $this->scheduler->start();

            /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
            if ($this->pending) {
                // Should only be true if the event loop exited without resolving the promise.
                throw new \Error('Event loop suspended or exited unexpectedly');
            }

            return $lambda();
        } finally {
            if (!empty(self::$listeners)) {
                $this->invokeListeners('onResume');
            }
        }
    }

    private function invokeListeners(string $method): void
    {
        $id = \spl_object_id($this);
        self::$invokingListeners = true;
        foreach (self::$listeners as $listener) {
            try {
                $listener->{$method}($id);
            } catch (\Throwable $exception) {
                $this->driver->queue(static fn () => throw $exception);
            }
        }
        self::$invokingListeners = false;
    }

    /**
     * Add a listener that is invoked when any Suspension is suspended, resumed, or destroyed.
     *
     * @param Listener $listener
     * @return string ID that can be used to remove the listener using {@see unlisten()}.
     */
    public static function listen(Listener $listener): string
    {
        $id = self::$nextId++;
        self::$listeners[$id] = $listener;
        return $id;
    }

    /**
     * Remove the suspension listener.
     *
     * @param string $id
     */
    public static function unlisten(string $id): void
    {
        unset(self::$listeners[$id]);
    }
}
