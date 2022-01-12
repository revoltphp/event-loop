<?php

namespace Revolt\EventLoop;

/**
 * Fiber local storage.
 *
 * Each instance stores data separately for each fiber. Usage examples include contextual logging data.
 */
final class FiberLocal
{
    /** @var \Fiber|null Dummy fiber for {main} */
    private static ?\Fiber $mainFiber = null;
    private static ?\WeakMap $localStorage = null;

    public static function clear(): void
    {
        $fiber = self::getFiber();
        $localStorage = self::getLocalStorage();

        unset($localStorage[$fiber]);
    }

    private static function getLocalStorage(): \WeakMap
    {
        return self::$localStorage ??= new \WeakMap();
    }

    private static function getFiber(): \Fiber
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $fiber = self::$mainFiber ??= new \Fiber(static function () {
                // dummy fiber for main, as we need some object for the WeakMap
            });
        }

        return $fiber;
    }

    public function __construct(mixed $value)
    {
        $this->set($value);
    }

    public function set(mixed $value): void
    {
        $fiber = self::getFiber();
        $localStorage = self::getLocalStorage();

        if (!isset($localStorage[$fiber])) {
            $localStorage[$fiber] = new \WeakMap();
        }

        $localStorage[$fiber][$this] = $value;
    }

    public function get(): mixed
    {
        $fiber = self::getFiber();
        $localStorage = self::getLocalStorage();

        return $localStorage[$fiber][$this] ?? null;
    }
}
