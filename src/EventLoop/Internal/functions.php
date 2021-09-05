<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop;

/**
 * Wrapper function used by {@see \Revolt\launch()} to create a fiber.
 *
 * @param callable $callback
 *
 * @internal
 */
function run(callable $callback): void
{
    try {
        $callback();
    } catch (\Throwable $exception) {
        EventLoop::queue(static fn () => throw $exception);
    }
}
