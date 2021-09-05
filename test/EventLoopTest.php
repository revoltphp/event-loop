<?php

namespace Revolt\EventLoop;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use function Revolt\launch;

class EventLoopTest extends TestCase
{
    public function testDelayWithNegativeDelay(): void
    {
        $this->expectException(\Error::class);

        EventLoop::delay(-1, fn () => null);
    }

    public function testRepeatWithNegativeInterval(): void
    {
        $this->expectException(\Error::class);

        EventLoop::repeat(-1, fn () => null);
    }

    public function testOnReadable(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        $ends = \stream_socket_pair(
            \stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        \fwrite($ends[0], "trigger readability watcher");

        $count = 0;
        $suspension = EventLoop::createSuspension();

        EventLoop::onReadable($ends[1], function ($watcher) use (&$count, $suspension): void {
            $this->assertTrue(true);
            EventLoop::cancel($watcher);
            $count++;

            $suspension->resume(null);
        });

        $suspension->suspend();

        self::assertSame(1, $count);
    }

    public function testOnWritable()
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        $count = 0;
        $suspension = EventLoop::createSuspension();

        EventLoop::onWritable(STDOUT, function ($watcher) use (&$count, $suspension): void {
            $this->assertTrue(true);
            EventLoop::cancel($watcher);
            $count++;

            $suspension->resume(null);
        });

        $suspension->suspend();

        self::assertSame(1, $count);
    }

    public function testGet(): void
    {
        self::assertInstanceOf(Driver::class, EventLoop::getDriver());
    }

    public function testGetInfo(): void
    {
        self::assertSame(EventLoop::getDriver()->getInfo(), EventLoop::getInfo());
    }

    public function testRun(): void
    {
        $invoked = false;
        EventLoop::defer(function () use (&$invoked): void {
            $invoked = true;
        });

        EventLoop::run();

        self::assertTrue($invoked);
    }

    public function testRunInFiber(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        launch(fn () => EventLoop::run());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("within a fiber");

        EventLoop::run();
    }

    public function testRunAfterSuspension(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        $suspension = EventLoop::createSuspension();

        EventLoop::defer(fn () => $suspension->resume('test'));

        self::assertSame($suspension->suspend(), 'test');

        $invoked = false;
        EventLoop::defer(function () use (&$invoked): void {
            $invoked = true;
        });

        EventLoop::run();

        self::assertTrue($invoked);
    }

    public function testSuspensionAfter(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        $invoked = false;
        EventLoop::defer(function () use (&$invoked): void {
            $invoked = true;
        });

        EventLoop::run();

        self::assertTrue($invoked);

        $suspension = EventLoop::createSuspension();

        EventLoop::defer(fn () => $suspension->resume('test'));

        self::assertSame($suspension->suspend(), 'test');
    }

    public function testSuspensionWithinFiberWithinRun(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        $invoked = false;
        launch(function () use (&$invoked): void {
            $suspension = EventLoop::createSuspension();

            EventLoop::defer(fn () => $suspension->resume('test'));

            self::assertSame($suspension->suspend(), 'test');

            $invoked = true;
        });

        EventLoop::run();

        self::assertTrue($invoked);
    }

    public function testSuspensionWithinWatcherCallback(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            self::markTestSkipped("Fibers required for this test");
        }

        $send = 42;

        EventLoop::defer(static function () use (&$received, $send): void {
            $suspension = EventLoop::createSuspension();
            EventLoop::defer(static fn () => $suspension->resume($send));
            $received = $suspension->suspend();
        });


        EventLoop::run();

        self::assertSame($send, $received);
    }
}
