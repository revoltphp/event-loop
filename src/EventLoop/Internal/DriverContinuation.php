<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Continuation;
use Revolt\EventLoop\Suspension;

/**
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class DriverContinuation implements Continuation
{
    private SuspensionState $state;

    private \Closure $queue;

    private \Closure $interrupt;

    /**
     * @param \Closure $queue
     * @param \Closure $interrupt
     *
     * @internal
     */
    public function __construct(\Closure $queue, \Closure $interrupt, SuspensionState $state)
    {
        $this->queue = $queue;
        $this->interrupt = $interrupt;
        $this->state = $state;

        // Add reference to fiber while this object exists.
        $this->state->addReference();
    }

    public function __destruct()
    {
        // Remove reference to fiber when this object is destroyed.
        $this->state->deleteReference();
    }

    public function isPending(): bool
    {
        return $this->state->pending;
    }

    public function resume(mixed $value = null): void
    {
        if (!$this->state->pending) {
            throw $this->state->fiberError ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->state->pending = false;

        if ($this->state->fiber) {
            ($this->queue)(\Closure::fromCallable([$this->state->fiber, 'resume']), $value);
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => $value);
        }
    }

    public function throw(\Throwable $throwable): void
    {
        if (!$this->state->pending) {
            throw $this->state->fiberError ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->state->pending = false;

        if ($this->state->fiber) {
            ($this->queue)(\Closure::fromCallable([$this->state->fiber, 'throw']), $throwable);
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => throw $throwable);
        }
    }
}
