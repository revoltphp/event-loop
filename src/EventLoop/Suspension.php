<?php

namespace Revolt\EventLoop;

/**
 * Should be used to run and suspend the event loop instead of directly interacting with fibers.
 *
 * **Example**
 *
 * ```php
 * $suspension = EventLoop::getSuspension();
 * $continuation = $suspension->getContinuation();
 *
 * $promise->then(
 *     fn (mixed $value) => $continuation->resume($value),
 *     fn (Throwable $error) => $continuation->throw($error)
 * );
 *
 * $suspension->suspend();
 * ```
 *
 * @template T
 */
interface Suspension
{
    /**
     * @return T
     */
    public function suspend(): mixed;

    /**
     * @return Continuation<T>
     */
    public function getContinuation(): Continuation;
}
