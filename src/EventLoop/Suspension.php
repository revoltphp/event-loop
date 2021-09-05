<?php

namespace Revolt\EventLoop;

use Revolt\EventLoop;

/**
 * Should be used to run and suspend the event loop instead of directly interacting with fibers.
 *
 * **Example**
 *
 * ```php
 * $suspension = Scheduler::createSuspension();
 *
 * $promise->then(fn ($value) => $suspension->resume($value), fn ($throwable) => $suspension->throw($throwable));
 *
 * $suspension->suspend();
 * ```
 */
final class Suspension
{
    private ?\Fiber $fiber;
    private \Fiber $scheduler;
    private Driver $driver;
    private bool $pending = false;

    /**
     * Suspension constructor.
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

        $this->pending = true;

        // Awaiting from within a fiber.
        if ($this->fiber) {
            return \Fiber::suspend();
        }

        // Awaiting from {main}.
        $lambda = $this->scheduler->isStarted() ? $this->scheduler->resume() : $this->scheduler->start();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            // Should only be true if the event loop exited without resolving the promise.
            throw new \Error('Scheduler suspended or exited unexpectedly');
        }

        return $lambda();
    }
}
