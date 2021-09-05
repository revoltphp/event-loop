<?php

namespace Revolt;

/**
 * Executes the given callback in a new fiber.
 *
 * Any exceptions thrown are forwarded to the event loop error handler. The return value of the function is discarded.
 *
 * @param callable():void $callback
 */
function launch(callable $callback): void
{
    $fiber = new \Fiber(__NAMESPACE__ . '\\EventLoop\\Internal\\run');
    EventLoop::queue([$fiber, 'start'], $callback);
}

/**
 * Returns the current time relative to an arbitrary point in time.
 *
 * @return float Time in seconds.
 */
function now(): float
{
    return (float) \hrtime(true) / 1_000_000_000;
}
