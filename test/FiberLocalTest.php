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

        EventLoop::queue(static function () use ($suspension, $fiberLocal) {
            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('initial', $suspension->suspend());

        EventLoop::queue(static function () use ($suspension, $fiberLocal) {
            $fiberLocal->set('fiber');

            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('fiber', $suspension->suspend());
        self::assertSame('initial', $fiberLocal->get());
    }

    public function testManualClear(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        self::assertSame('initial', $fiberLocal->get());

        $suspension = EventLoop::getSuspension();

        EventLoop::queue(static function () use ($suspension, $fiberLocal, &$fiberSuspension) {
            $fiberSuspension = EventLoop::getSuspension();

            $fiberLocal->set('fiber');
            $suspension->resume($fiberLocal->get());

            $fiberSuspension->suspend();
            $suspension->resume($fiberLocal->get());

            $fiberSuspension->suspend();
            FiberLocal::clear();
            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('fiber', $suspension->suspend());
        self::assertSame('initial', $fiberLocal->get());

        FiberLocal::clear();

        $fiberSuspension->resume();

        self::assertSame('fiber', $suspension->suspend());
        self::assertSame('initial', $fiberLocal->get());

        $fiberSuspension->resume();

        self::assertSame('initial', $suspension->suspend());
        self::assertSame('initial', $fiberLocal->get());
    }

    public function testCallbackFiberClear(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        $suspension = EventLoop::getSuspension();

        EventLoop::defer(static function () use ($fiberLocal, &$fiber1) {
            $fiberLocal->set('fiber');
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::defer(static function () use ($suspension, $fiberLocal, &$fiber2) {
            $fiber2 = \Fiber::getCurrent();
            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('initial', $suspension->suspend());
        self::assertSame($fiber1, $fiber2);
    }

    public function testMicrotaskFiberClear(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        $suspension = EventLoop::getSuspension();

        EventLoop::queue(static function () use ($fiberLocal, &$fiber1) {
            $fiberLocal->set('fiber');
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::queue(static function () use ($suspension, $fiberLocal, &$fiber2) {
            $fiber2 = \Fiber::getCurrent();
            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('initial', $suspension->suspend());
        self::assertSame($fiber1, $fiber2);
    }

    public function testMicrotaskAfterSuspension(): void
    {
        $fiberLocal = new FiberLocal(fn () => 'initial');

        $mainSuspension = EventLoop::getSuspension();

        EventLoop::queue(static function () use ($fiberLocal, $mainSuspension) {
            $fiberLocal->set('fiber');

            $suspension = EventLoop::getSuspension();
            EventLoop::defer(static fn () => $suspension->resume());
            $suspension->suspend();

            $mainSuspension->resume($fiberLocal->get());
        });

        self::assertSame('fiber', $mainSuspension->suspend());
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
}
