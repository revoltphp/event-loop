<?php

namespace Revolt\EventLoop;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

class FiberLocalTest extends TestCase
{
    public function test(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        self::assertSame('initial', $fiberLocal->get());

        $suspension = EventLoop::getSuspension();
        $continuation = $suspension->getContinuation();

        EventLoop::queue(static function () use ($continuation, $fiberLocal) {
            $continuation->resume($fiberLocal->get());
        });

        self::assertSame('initial', EventLoop::getSuspension()->suspend());

        EventLoop::queue(static function () use ($continuation, $fiberLocal) {
            $fiberLocal->set('fiber');

            $continuation->resume($fiberLocal->get());
        });

        self::assertSame('fiber', EventLoop::getSuspension()->suspend());
        self::assertSame('initial', $fiberLocal->get());
    }

    public function testManualClear(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        self::assertSame('initial', $fiberLocal->get());

        $suspension = EventLoop::getSuspension();
        $continuation = $suspension->getContinuation();

        EventLoop::queue(static function () use ($continuation, $fiberLocal, &$fiberContinuation) {
            $fiberSuspension = EventLoop::getSuspension();
            $fiberContinuation = $fiberSuspension->getContinuation();

            $fiberLocal->set('fiber');
            $continuation->resume($fiberLocal->get());

            EventLoop::getSuspension()->suspend();
            $continuation->resume($fiberLocal->get());

            EventLoop::getSuspension()->suspend();
            FiberLocal::clear();
            $continuation->resume($fiberLocal->get());
        });

        self::assertSame('fiber', EventLoop::getSuspension()->suspend());
        self::assertSame('initial', $fiberLocal->get());

        FiberLocal::clear();

        $fiberContinuation->resume();

        self::assertSame('fiber', EventLoop::getSuspension()->suspend());
        self::assertSame('initial', $fiberLocal->get());

        $fiberContinuation->resume();

        self::assertSame('initial', EventLoop::getSuspension()->suspend());
        self::assertSame('initial', $fiberLocal->get());
    }

    public function testCallbackFiberClear(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        $suspension = EventLoop::getSuspension();
        $continuation = $suspension->getContinuation();

        EventLoop::defer(static function () use ($fiberLocal, &$fiber1) {
            $fiberLocal->set('fiber');
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::defer(static function () use ($continuation, $fiberLocal, &$fiber2) {
            $fiber2 = \Fiber::getCurrent();
            $continuation->resume($fiberLocal->get());
        });

        self::assertSame('initial', EventLoop::getSuspension()->suspend());
        self::assertSame($fiber1, $fiber2);
    }

    public function testMicrotaskFiberClear(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        $suspension = EventLoop::getSuspension();
        $continuation = $suspension->getContinuation();

        EventLoop::queue(static function () use ($fiberLocal, &$fiber1) {
            $fiberLocal->set('fiber');
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::queue(static function () use ($continuation, $fiberLocal, &$fiber2) {
            $fiber2 = \Fiber::getCurrent();
            $continuation->resume($fiberLocal->get());
        });

        self::assertSame('initial', EventLoop::getSuspension()->suspend());
        self::assertSame($fiber1, $fiber2);
    }

    public function testMicrotaskAfterSuspension(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        $mainSuspension = EventLoop::getSuspension();
        $mainContinuation = $mainSuspension->getContinuation();

        EventLoop::queue(static function () use ($fiberLocal, $mainContinuation) {
            $fiberLocal->set('fiber');

            $suspension = EventLoop::getSuspension();
            $continuation = $suspension->getContinuation();
            EventLoop::defer(static fn () => $continuation->resume());
            EventLoop::getSuspension()->suspend();

            $mainContinuation->resume($fiberLocal->get());
        });

        self::assertSame('fiber', EventLoop::getSuspension()->suspend());
    }

    public function testInitializeWithNull(): void
    {
        $invoked = 0;
        $fiberLocal = new FiberLocal(function () use (&$invoked) {
            ++$invoked;
            return null;
        });

        self::assertNull($fiberLocal->get());
        self::assertNull($fiberLocal->get());
        self::assertSame(1, $invoked);
    }

    public function testInitializeThrow(): void
    {
        $fiberLocal = new FiberLocal(fn () => throw new \Exception('test'));

        self::assertThrows(static fn () => $fiberLocal->get(), \Exception::class);

        // throws repeatedly
        self::assertThrows(static fn () => $fiberLocal->get(), \Exception::class);

        $fiberLocal->set(null);
        self::assertNull($fiberLocal->get());

        $fiberLocal->unset();
        self::assertThrows(static fn () => $fiberLocal->get(), \Exception::class);
    }

    private static function assertThrows(\Closure $closure, string $exceptionClass): void
    {
        try {
            $closure();
            self::fail('Expected ' . $exceptionClass . ' to be thrown');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
        }
    }
}
