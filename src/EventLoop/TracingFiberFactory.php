<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Fiber factory which collects all created fibers in a weak map.
 *
 * @implements IteratorAggregate<\Fiber, null>
 */
final class TracingFiberFactory implements FiberFactory, Countable, IteratorAggregate
{
    /**
     * @var \WeakMap<\Fiber, null>
     */
    private readonly \WeakMap $map;

    public function __construct(
        private readonly FiberFactory $fiberFactory = new DefaultFiberFactory()
    ) {
        /** @var \WeakMap<\Fiber, null> */
        $this->map = new \WeakMap();
    }

    /**
     * Creates a new fiber instance.
     *
     * @param callable $callable The callable to invoke when starting the fiber.
     *
     * @return \Fiber
     */
    public function create(callable $callback): \Fiber
    {
        $f = $this->fiberFactory->create($callback);
        /** @psalm-suppress InaccessibleProperty */
        $this->map[$f] = null;
        return $f;
    }

    /**
     * Returns the number of running fibers.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->map->count();
    }

    /**
     * Iterate over all fibers currently in scope.
     *
     * @return Traversable<\Fiber, null>
     */
    public function getIterator(): Traversable
    {
        return $this->map->getIterator();
    }
}
