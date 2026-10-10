<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

final class DefaultFiberFactory implements FiberFactory
{
    /**
     * Creates a new fiber instance.
     *
     * @param callable $callable The callable to invoke when starting the fiber.
     *
     * @return \Fiber
     */
    public function create(callable $callback): \Fiber
    {
        return new \Fiber($callback);
    }
}
