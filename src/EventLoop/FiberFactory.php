<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

interface FiberFactory
{
    /**
     * Creates a new fiber instance.
     *
     * @param callable $callable The callable to invoke when starting the fiber.
     *
     * @return \Fiber
     */
    public function create(callable $callback): \Fiber;
}
