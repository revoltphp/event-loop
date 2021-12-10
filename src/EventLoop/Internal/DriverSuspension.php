<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Suspension;

/**
 * @internal
 */
final class DriverSuspension implements Suspension
{
    private ?\Fiber $fiber;

    private ?\FiberError $fiberError = null;

    private \Closure $run;

    private \Closure $queue;

    private \Closure $interrupt;

    private bool $pending = false;

    /**
     * @param \Closure $run
     * @param \Closure $queue
     * @param \Closure $interrupt
     *
     * @internal
     */
    public function __construct(\Closure $run, \Closure $queue, \Closure $interrupt)
    {
        $this->run = $run;
        $this->queue = $queue;
        $this->interrupt = $interrupt;
        $this->fiber = \Fiber::getCurrent();
    }

    public function resume(mixed $value = null): void
    {
        if (!$this->pending) {
            throw $this->fiberError ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->pending = false;

        if ($this->fiber) {
            ($this->queue)(\Closure::fromCallable([$this->fiber, 'resume']), $value);
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => $value);
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
            try {
                return \Fiber::suspend();
            } catch (\FiberError $exception) {
                $this->pending = false;
                $this->fiberError = $exception;

                throw $exception;
            }
        }

        // Awaiting from {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            $result && $result(); // Unwrap any uncaught exceptions from the event loop

            throw new \Error('Event loop terminated without resuming the current suspension');
        }

        return $result();
    }

    public function throw(\Throwable $throwable): void
    {
        if (!$this->pending) {
            throw $this->fiberError ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->pending = false;

        if ($this->fiber) {
            ($this->queue)(\Closure::fromCallable([$this->fiber, 'throw']), $throwable);
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => throw $throwable);
        }
    }
}
