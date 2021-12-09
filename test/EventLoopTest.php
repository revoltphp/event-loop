<?php

namespace Revolt\EventLoop;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

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
        $ends = \stream_socket_pair(
            \DIRECTORY_SEPARATOR === "\\" ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        \fwrite($ends[0], "trigger readability callback");

        $count = 0;
        $suspension = EventLoop::createSuspension();

        EventLoop::onReadable($ends[1], function ($callbackId) use (&$count, $suspension): void {
            $this->assertTrue(true);
            EventLoop::cancel($callbackId);
            $count++;

            $suspension->resume();
        });

        $suspension->suspend();

        self::assertSame(1, $count);
    }

    public function testOnWritable(): void
    {
        $count = 0;
        $suspension = EventLoop::createSuspension();

        EventLoop::onWritable(STDOUT, function ($callbackId) use (&$count, $suspension): void {
            $this->assertTrue(true);
            EventLoop::cancel($callbackId);
            $count++;

            $suspension->resume();
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

    public function testFiberReuse(): void
    {
        EventLoop::defer(function () use (&$fiber1): void {
            $fiber1 = \Fiber::getCurrent();
        });

        EventLoop::defer(function () use (&$fiber2): void {
            $fiber2 = \Fiber::getCurrent();
        });

        EventLoop::run();

        self::assertNotNull($fiber1);
        self::assertNotNull($fiber2);
        self::assertSame($fiber1, $fiber2);
    }

    public function testRunInFiber(): void
    {
        EventLoop::queue(fn () => EventLoop::run());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("The event loop is already running");

        EventLoop::run();
    }

    public function testRunAfterSuspension(): void
    {
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

    public function testSuspensionAfterRun(): void
    {
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

    public function testSuspensionWithinFiber(): void
    {
        $invoked = false;

        EventLoop::queue(function () use (&$invoked): void {
            $suspension = EventLoop::createSuspension();

            EventLoop::defer(fn () => $suspension->resume('test'));

            self::assertSame($suspension->suspend(), 'test');

            $invoked = true;
        });

        EventLoop::run();

        self::assertTrue($invoked);
    }

    public function testSuspensionWithinCallback(): void
    {
        $send = 42;

        EventLoop::defer(static function () use (&$received, $send): void {
            $suspension = EventLoop::createSuspension();
            EventLoop::defer(static fn () => $suspension->resume($send));
            $received = $suspension->suspend();
        });

        EventLoop::run();

        self::assertSame($send, $received);
    }

    public function testSuspensionWithinQueue(): void
    {
        $send = 42;

        EventLoop::queue(static function () use (&$received, $send): void {
            $suspension = EventLoop::createSuspension();
            EventLoop::defer(static fn () => $suspension->resume($send));
            $received = $suspension->suspend();
        });

        EventLoop::run();

        self::assertSame($send, $received);
    }

    public function testSuspensionThrowingErrorViaInterrupt(): void
    {
        $suspension = EventLoop::createSuspension();
        $error = new \Error("Test error");
        EventLoop::queue(static fn () => throw $error);
        EventLoop::defer(static fn () => $suspension->resume("Value"));
        try {
            $suspension->suspend();
            self::fail("Error was not thrown");
        } catch (UncaughtThrowable $t) {
            self::assertSame($error, $t->getPrevious());
        }
    }
}
