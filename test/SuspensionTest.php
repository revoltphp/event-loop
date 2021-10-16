<?php

namespace Revolt\EventLoop;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

class SuspensionTest extends TestCase
{
    public function testListen(): void
    {
        $listener = new class () implements Listener {
            public int $suspended = 0;
            public int $resumed = 0;

            public function onSuspend(int $id): void
            {
                ++$this->suspended;
            }

            public function onResume(int $id): void
            {
                ++$this->resumed;
            }
        };

        $id = Suspension::listen($listener);

        $suspension = EventLoop::createSuspension();
        EventLoop::defer(fn () => $suspension->resume(null));

        $suspension->suspend();

        self::assertSame(1, $listener->suspended);
        self::assertSame(1, $listener->resumed);

        Suspension::listen($listener);

        $suspension = EventLoop::createSuspension();
        EventLoop::defer(fn () => $suspension->throw(new \Exception()));

        try {
            $suspension->suspend();
            self::fail('Exception was expected to be thrown from suspend');
        } catch (\Exception $e) {
            // Expected, ignore.
        }

        self::assertSame(3, $listener->suspended);
        self::assertSame(3, $listener->resumed);

        Suspension::unlisten($id);

        $suspension = EventLoop::createSuspension();
        EventLoop::defer(fn () => $suspension->resume(null));

        $suspension->suspend();

        self::assertSame(4, $listener->suspended);
        self::assertSame(4, $listener->resumed);
    }

    public function provideListenerMethods(): iterable
    {
        $reflectionClass = new \ReflectionClass(Listener::class);
        $methods = $reflectionClass->getMethods();
        return \array_map(static fn (\ReflectionMethod $reflectionMethod) => [$reflectionMethod->getName()], $methods);
    }

    /**
     * @dataProvider provideListenerMethods
     */
    public function testSuspendDuringListenerInvocation(string $functionName): void
    {
        $suspension = EventLoop::createSuspension();

        $listener = new class ($functionName, $suspension) implements Listener {
            public function __construct(
                private string $functionName,
                private Suspension $suspension,
            ) {
            }

            public function onSuspend(int $id): void
            {
                if ($this->functionName === __FUNCTION__) {
                    $this->suspension->suspend();
                }
            }

            public function onResume(int $id): void
            {
                if ($this->functionName === __FUNCTION__) {
                    $this->suspension->suspend();
                }
            }
        };

        Suspension::listen($listener);

        $suspension = EventLoop::createSuspension();
        EventLoop::defer(fn () => $suspension->resume(null));

        self::expectException(\Error::class);
        self::expectExceptionMessage('within a suspension listener');

        $suspension->suspend();
    }

    /**
     * @dataProvider provideListenerMethods
     */
    public function testResumeDuringListenerInvocation(string $functionName): void
    {
        $suspension = EventLoop::createSuspension();

        $listener = new class ($functionName, $suspension) implements Listener {
            public function __construct(
                private string $functionName,
                private Suspension $suspension,
            ) {
            }

            public function onSuspend(int $id): void
            {
                if ($this->functionName === __FUNCTION__) {
                    $this->suspension->resume(null);
                }
            }

            public function onResume(int $id): void
            {
                if ($this->functionName === __FUNCTION__) {
                    $this->suspension->resume(null);
                }
            }
        };

        Suspension::listen($listener);

        self::expectException(\Error::class);
        self::expectExceptionMessage('within a suspension listener');

        $suspension->suspend();
    }

    /**
     * @dataProvider provideListenerMethods
     */
    public function testThrowDuringListenerInvocation(string $functionName): void
    {
        $suspension = EventLoop::createSuspension();

        $listener = new class ($functionName, $suspension) implements Listener {
            public function __construct(
                private string $functionName,
                private Suspension $suspension,
            ) {
            }

            public function onSuspend(int $id): void
            {
                if ($this->functionName === __FUNCTION__) {
                    $this->suspension->throw(new \Exception());
                }
            }

            public function onResume(int $id): void
            {
                if ($this->functionName === __FUNCTION__) {
                    $this->suspension->throw(new \Exception());
                }
            }
        };

        Suspension::listen($listener);

        self::expectException(\Error::class);
        self::expectExceptionMessage('within a suspension listener');

        $suspension->suspend();
    }
}
