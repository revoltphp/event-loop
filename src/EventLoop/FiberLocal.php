<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * Fiber local storage.
 *
 * Each instance stores data separately for each fiber. Usage examples include contextual logging data.
 *
 * @template T
 */
final class FiberLocal
{
    /** @var object|null Dummy object for {main} */
    private static ?object $dummyMain = null;
    private static ?\WeakMap $localStorage = null;

    public static function clear(): void
    {
        if (self::$localStorage === null) {
            return;
        }

        $fiber = \Fiber::getCurrent() ?? self::$dummyMain;

        if ($fiber === null) {
            return;
        }

        unset(self::$localStorage[$fiber]);
    }

    private static function getFiberStorage(): \WeakMap
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $fiber = self::$dummyMain ??= new class () {
            };
        }

        $localStorage = self::$localStorage ??= new \WeakMap();
        return $localStorage[$fiber] ??= new \WeakMap();
    }

    /**
     * @param \Closure():T $initializer
     */
    public function __construct(private readonly \Closure $initializer)
    {
    }

    /**
     * @param T $value
     */
    public function set(mixed $value): void
    {
        self::getFiberStorage()[$this] = [$value];
    }

    public function unset(): void
    {
        unset(self::getFiberStorage()[$this]);
    }

    /**
     * @return T
     */
    public function get(): mixed
    {
        $fiberStorage = self::getFiberStorage();

        if (!isset($fiberStorage[$this])) {
            $fiberStorage[$this] = [($this->initializer)()];
        }

        return $fiberStorage[$this][0];
    }
}
