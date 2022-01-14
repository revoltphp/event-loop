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
        $suspension = EventLoop::getSuspension();

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
        $suspension = EventLoop::getSuspension();

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
        $suspension = EventLoop::getSuspension();

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

        $suspension = EventLoop::getSuspension();

        EventLoop::defer(fn () => $suspension->resume('test'));

        self::assertSame($suspension->suspend(), 'test');
    }

    public function testSuspensionWithinFiber(): void
    {
        $invoked = false;

        EventLoop::queue(function () use (&$invoked): void {
            $suspension = EventLoop::getSuspension();

            EventLoop::defer(fn () => $suspension->resume('test'));

            self::assertSame($suspension->suspend(), 'test');

            $invoked = true;
        });

        EventLoop::run();

        self::assertTrue($invoked);
    }

    public function testDoubleResumeWithinFiber(): void
    {
        $suspension = EventLoop::createSuspension();

        EventLoop::queue(static function () use ($suspension): void {
            $suspension->resume();
            $suspension->resume();
        });

        $this->expectException(UncaughtThrowable::class);
        $this->expectExceptionMessage('Must call suspend() before calling resume()');

        $suspension->suspend();
    }

    public function testSuspensionWithinCallback(): void
    {
        $send = 42;

        EventLoop::defer(static function () use (&$received, $send): void {
            $suspension = EventLoop::getSuspension();
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
            $suspension = EventLoop::getSuspension();
            EventLoop::defer(static fn () => $suspension->resume($send));
            $received = $suspension->suspend();
        });

        EventLoop::run();

        self::assertSame($send, $received);
    }

    public function testSuspensionThrowingErrorViaInterrupt(): void
    {
        $suspension = EventLoop::getSuspension();
        $error = new \Error("Test error");
        EventLoop::queue(static fn () => throw $error);
        try {
            $suspension->suspend();
            self::fail("Error was not thrown");
        } catch (UncaughtThrowable $t) {
            self::assertSame($error, $t->getPrevious());
        }
    }
}
