<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

class EventLoopTest extends TestCase
{
    public function testSuspensionResumptionWithQueueInGarbageCollection(): void
    {
        $suspension = EventLoop::getSuspension();

        $class = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                $this->suspension->resume(true);
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $cycle);

        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }

    public function testEventLoopResumptionWithQueueInGarbageCollection(): void
    {
        $suspension = EventLoop::getSuspension();

        $class = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                EventLoop::queue($this->suspension->resume(...), true);
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $cycle);

        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }


    public function testSuspensionResumptionWithQueueInGarbageCollectionNested(): void
    {
        $suspension = EventLoop::getSuspension();

        $resumer = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                $this->suspension->resume(true);
            }
        };

        $class = new class ($resumer) {
            public static ?object $staticReference = null;
            public function __construct(object $resumer)
            {
                self::$staticReference = $resumer;
            }
            public function __destruct()
            {
                EventLoop::queue(function () {
                    $class = self::$staticReference;
                    $cycle = [$class, &$cycle];
                    unset($class, $cycle);

                    self::$staticReference = null;
                });
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $resumer, $cycle);


        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }

    public function testEventLoopResumptionWithQueueInGarbageCollectionNested(): void
    {
        $suspension = EventLoop::getSuspension();

        $resumer = new class ($suspension) {
            public function __construct(public Suspension $suspension)
            {
            }
            public function __destruct()
            {
                EventLoop::queue($this->suspension->resume(...), true);
            }
        };

        $class = new class ($resumer) {
            public static ?object $staticReference = null;
            public function __construct(object $resumer)
            {
                self::$staticReference = $resumer;
            }
            public function __destruct()
            {
                EventLoop::queue(function () {
                    $class = self::$staticReference;
                    $cycle = [$class, &$cycle];
                    unset($class, $cycle);

                    self::$staticReference = null;
                });
            }
        };
        $cycle = [$class, &$cycle];
        unset($class, $resumer, $cycle);


        $ended = $suspension->suspend();

        $this->assertTrue($ended);
    }


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

    public function testReflection(): void
    {
        self::assertSame([], EventLoop::getIdentifiers());

        $id = EventLoop::delay(5, fn () => null);

        self::assertSame([$id], EventLoop::getIdentifiers());
        self::assertSame(CallbackType::Delay, EventLoop::getType($id));
        self::assertTrue(EventLoop::isReferenced($id));
        self::assertTrue(EventLoop::isEnabled($id));

        EventLoop::disable($id);
        self::assertFalse(EventLoop::isEnabled($id));
        self::assertTrue(EventLoop::isReferenced($id));

        EventLoop::unreference($id);
        self::assertFalse(EventLoop::isEnabled($id));
        self::assertFalse(EventLoop::isReferenced($id));

        EventLoop::enable($id);
        self::assertTrue(EventLoop::isEnabled($id));
        self::assertFalse(EventLoop::isReferenced($id));

        EventLoop::reference($id);
        self::assertTrue(EventLoop::isEnabled($id));
        self::assertTrue(EventLoop::isReferenced($id));

        EventLoop::cancel($id);

        self::assertSame([], EventLoop::getIdentifiers());
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
        $suspension = EventLoop::getSuspension();

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

    public function testSuspensionWithinCallbackGarbageCollection(): void
    {
        $memory = 0;
        $i = 0;

        EventLoop::repeat(0, static function (string $id) use (&$memory, &$i) {
            $suspension = EventLoop::getSuspension();
            EventLoop::defer(static fn () => $suspension->resume());
            $suspension->suspend();

            if (++$i % 250 === 0) {
                if ($i > 500) {
                    self::assertSame($memory, \memory_get_usage());
                }

                $memory = \memory_get_usage();
            }

            if ($i === 10000) {
                EventLoop::cancel($id);
            }
        });

        EventLoop::run();
    }

    public function testSuspensionWithinCallbackGarbageCollectionSuspended(): void
    {
        EventLoop::defer(static function () use (&$finally): void {
            $suspension = EventLoop::getSuspension();

            try {
                $suspension->suspend();
            } finally {
                $finally = true;
            }
        });

        EventLoop::run();

        \gc_collect_cycles();

        self::assertTrue($finally);
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

    public function testResumedQueueFibersDoNotNestInEachOther(): void
    {
        $fibers = 25;
        $suspensions = [];
        $depths = [];

        for ($i = 0; $i < $fibers; $i++) {
            EventLoop::queue(static function () use (&$suspensions, &$depths): void {
                $suspension = EventLoop::getSuspension();
                $suspensions[] = $suspension;
                $suspension->suspend();

                $exception = new \Exception();
                $depths[] = \count($exception->getTrace());
            });
        }

        EventLoop::defer(static function () use (&$suspensions): void {
            foreach ($suspensions as $suspension) {
                $suspension->resume();
            }
        });

        EventLoop::run();

        self::assertCount($fibers, $depths);
        self::assertSame($depths[0], \max($depths));
    }

    public function testResumedCallbackFibersDoNotNestInEachOther(): void
    {
        $fibers = 25;
        $suspensions = [];
        $depths = [];

        for ($i = 0; $i < $fibers; $i++) {
            EventLoop::defer(static function () use (&$suspensions, &$depths): void {
                $suspension = EventLoop::getSuspension();
                $suspensions[] = $suspension;
                $suspension->suspend();

                $exception = new \Exception();
                $depths[] = \count($exception->getTrace());
            });
        }

        EventLoop::delay(0, static function () use (&$suspensions): void {
            foreach ($suspensions as $suspension) {
                $suspension->resume();
            }
        });

        EventLoop::run();

        self::assertCount($fibers, $depths);
        self::assertSame($depths[0], \max($depths));
    }

    public function testDeferredCallbackDoesNotRunInsideResumedFiber(): void
    {
        EventLoop::queue(static function () use (&$suspension): void {
            $suspension = EventLoop::getSuspension();
            $suspension->suspend();
        });

        EventLoop::defer(static function () use (&$suspension, &$resumingDepth): void {
            $exception = new \Exception();
            $resumingDepth = \count($exception->getTrace());

            $suspension->resume();
        });

        EventLoop::defer(static function () use (&$nextDepth): void {
            $exception = new \Exception();
            $nextDepth = \count($exception->getTrace());
        });

        EventLoop::run();

        self::assertSame($resumingDepth, $nextDepth);
    }

    public function testResumedFibersRunInOrderWithQueuedMicrotasks(): void
    {
        $order = [];

        EventLoop::queue(static function () use (&$first, &$order): void {
            $first = EventLoop::getSuspension();
            $first->suspend();
            $order[] = 'first';
        });

        EventLoop::queue(static function () use (&$second, &$order): void {
            $second = EventLoop::getSuspension();
            $second->suspend();
            $order[] = 'second';
        });

        EventLoop::defer(static function () use (&$first, &$second, &$order): void {
            $first->resume();
            EventLoop::queue(static function () use (&$order): void {
                $order[] = 'microtask';
            });
            $second->resume();
        });

        EventLoop::run();

        self::assertSame(['first', 'microtask', 'second'], $order);
    }

    public function testExceptionFromResumedFiberLeavesNextMicrotaskToNextRun(): void
    {
        $exception = new \Exception();
        $invoked = false;

        EventLoop::queue(static function () use (&$suspension, $exception): void {
            $suspension = EventLoop::getSuspension();
            $suspension->suspend();

            throw $exception;
        });

        EventLoop::defer(static function () use (&$suspension, &$invoked): void {
            $suspension->resume();
            EventLoop::queue(static function () use (&$invoked): void {
                $invoked = true;
            });
        });

        try {
            EventLoop::run();
            self::fail('The exception must leave the event loop');
        } catch (UncaughtThrowable $throwable) {
            self::assertSame($exception, $throwable->getPrevious());
        }

        self::assertFalse($invoked);

        EventLoop::run();

        self::assertTrue($invoked);
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

        $suspension->resume(); // Calling resume on the same suspension should not throw an Error.
        $suspension->throw(new \RuntimeException()); // Calling throw on the same suspension should not throw an Error.

        try {
            $suspension->suspend(); // Calling suspend on the same suspension should throw an Error.
            self::fail("Error was not thrown");
        } catch (\Error $e) {
            self::assertStringContainsString('suspended after an uncaught exception', $e->getMessage());
        }

        // Creating a new Suspension and re-entering the event loop (e.g. in a shutdown function) should work.
        $suspension = EventLoop::getSuspension();
        EventLoop::queue($suspension->resume(...));
        $suspension->suspend();
    }

    public function testSuspensionThrowingErrorViaInterrupt2(): void
    {
        $suspension = EventLoop::getSuspension();
        $error = new \Error("Test error");
        EventLoop::queue(static fn () => throw $error);
        EventLoop::queue($suspension->resume(...), 123);
        try {
            $suspension->suspend();
            self::fail("Error was not thrown");
        } catch (UncaughtThrowable $t) {
            self::assertSame($error, $t->getPrevious());
        }

        $suspension->resume(); // Calling resume on the same suspension should not throw an Error.
        $suspension->throw(new \RuntimeException()); // Calling throw on the same suspension should not throw an Error.

        try {
            $suspension->suspend(); // Calling suspend on the same suspension should throw an Error.
            self::fail("Error was not thrown");
        } catch (\Error $e) {
            self::assertStringContainsString('suspended after an uncaught exception', $e->getMessage());
        }

        // Creating a new Suspension and re-entering the event loop (e.g. in a shutdown function) should work.
        $suspension = EventLoop::getSuspension();
        EventLoop::queue($suspension->resume(...), 321);
        $this->assertEquals(321, $suspension->suspend());
    }

    public function testFiberDestroyedWhileSuspended(): void
    {
        $outer = new class (new class ($this) {
            private ?Suspension $suspension = null;

            public function __construct(public object $outer)
            {
            }

            public function suspend(): void
            {
                $this->suspension = EventLoop::getSuspension();
                $this->suspension->suspend();
            }

            public function __destruct()
            {
                echo 'object destroyed';
                $suspension = $this->suspension;
                $this->suspension = null;
                EventLoop::defer(static fn () => $suspension?->resume());
            }
        }) {
            public function __construct(public object $inner)
            {
            }
        };

        $inner = $outer->inner;
        unset($outer);

        EventLoop::queue(static fn () => $inner->suspend());
        unset($inner);

        EventLoop::queue(\gc_collect_cycles(...));

        $this->expectOutputString('object destroyed');

        EventLoop::run();
    }
}
