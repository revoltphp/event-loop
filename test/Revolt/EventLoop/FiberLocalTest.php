<?php

namespace Revolt\EventLoop;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

class FiberLocalTest extends TestCase
{
    public function test(): void
    {
        $fiberLocal = new FiberLocal('main');

        self::assertSame('main', $fiberLocal->get());

        $suspension = EventLoop::createSuspension();

        EventLoop::queue(static function () use ($suspension, $fiberLocal) {
            $suspension->resume($fiberLocal->get());
        });

        self::assertNull($suspension->suspend());

        EventLoop::queue(static function () use ($suspension, $fiberLocal) {
            $fiberLocal->set('fiber');

            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('fiber', $suspension->suspend());
        self::assertSame('main', $fiberLocal->get());
    }

    public function testManualClear(): void
    {
        $fiberLocal = new FiberLocal('main');

        self::assertSame('main', $fiberLocal->get());

        $suspension = EventLoop::createSuspension();

        EventLoop::queue(static function () use ($suspension, $fiberLocal, &$fiberSuspension) {
            $fiberSuspension = EventLoop::createSuspension();

            $fiberLocal->set('fiber');
            $suspension->resume($fiberLocal->get());

            $fiberSuspension->suspend();
            $suspension->resume($fiberLocal->get());

            $fiberSuspension->suspend();
            FiberLocal::clear();
            $suspension->resume($fiberLocal->get());
        });

        self::assertSame('fiber', $suspension->suspend());
        self::assertSame('main', $fiberLocal->get());

        FiberLocal::clear();

        $fiberSuspension->resume();

        self::assertSame('fiber', $suspension->suspend());
        self::assertNull($fiberLocal->get());

        $fiberSuspension->resume();

        self::assertNull($suspension->suspend());
        self::assertNull($fiberLocal->get());
    }

    public function testCallbackFiberClear(): void
    {
        $fiberLocal = new FiberLocal('main');

        $suspension = EventLoop::createSuspension();

        EventLoop::defer(static function () use ($fiberLocal, &$fiber1) {
            $fiberLocal->set('fiber');
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::defer(static function () use ($suspension, $fiberLocal, &$fiber2) {
            $fiber2 = \Fiber::getCurrent();
            $suspension->resume($fiberLocal->get());
        });

        self::assertNull($suspension->suspend());
        self::assertSame($fiber1, $fiber2);
    }

    public function testMicrotaskFiberClear(): void
    {
        $fiberLocal = new FiberLocal('main');

        $suspension = EventLoop::createSuspension();

        EventLoop::queue(static function () use ($fiberLocal, &$fiber1) {
            $fiberLocal->set('fiber');
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::queue(static function () use ($suspension, $fiberLocal, &$fiber2) {
            $fiber2 = \Fiber::getCurrent();
            $suspension->resume($fiberLocal->get());
        });

        self::assertNull($suspension->suspend());
        self::assertSame($fiber1, $fiber2);
    }
}
