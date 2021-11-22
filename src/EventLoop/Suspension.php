<?php

namespace Revolt\EventLoop;

/**
 * Should be used to run and suspend the event loop instead of directly interacting with fibers.
 *
 * **Example**
 *
 * ```php
 * $suspension = EventLoop::createSuspension();
 *
 * $promise->then(
 *     fn (mixed $value) => $suspension->resume($value),
 *     fn (Throwable $error) => $suspension->throw($error)
 * );
 *
 * $suspension->suspend();
 * ```
 */
interface Suspension
{
    public function resume(mixed $value = null): void;

    public function suspend(): mixed;

    public function throw(\Throwable $throwable): void;
}
