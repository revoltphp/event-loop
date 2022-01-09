<?php

namespace Revolt\EventLoop\Internal;

/**
 * @internal
 */
abstract class DriverCallback
{
    public bool $invokable = false;

    public bool $enabled = true;

    public bool $referenced = true;

    public \Closure $closure;

    public function __construct(
        public string $id,
        \Closure $closure
    ) {
        $this->closure = $closure;
    }

    /**
     * @param string $property
     *
     * @psalm-return no-return
     */
    public function __get(string $property): void
    {
        throw new \Error("Unknown property '${property}'");
    }

    /**
     * @param string $property
     * @param mixed  $value
     *
     * @psalm-return no-return
     */
    public function __set(string $property, mixed $value): void
    {
        throw new \Error("Unknown property '${property}'");
    }
}
