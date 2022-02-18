<?php

namespace Revolt\EventLoop;

/**
 * Should be accessed from {@see Suspension::getContinuation()}. This object is used to resume a coroutine that is
 * suspended with {@see Suspension::suspend()}.
 *
 * @template T
 */
interface Continuation
{
    /**
     * @param T $value
     *
     * @return void
     */
    public function resume(mixed $value = null): void;

    public function throw(\Throwable $throwable): void;
}
