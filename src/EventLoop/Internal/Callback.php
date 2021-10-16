<?php

namespace Revolt\EventLoop\Internal;

/**
 * @internal
 */
abstract class Callback
{
    public bool $enabled = true;

    public bool $referenced = true;

    /** @var callable */
    public $callback;

    public function __construct(
        public string $id,
        callable $callback
    ) {
        $this->callback = $callback;
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
