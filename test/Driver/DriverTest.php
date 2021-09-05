<?php

namespace Revolt\EventLoop\Driver;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidWatcherError;
use Revolt\EventLoop\UnsupportedFeatureException;
use function Revolt\now;

if (!\defined("SIGUSR1")) {
    \define("SIGUSR1", 30);
}
if (!\defined("SIGUSR2")) {
    \define("SIGUSR2", 31);
}

if (!\defined("PHP_INT_MIN")) {
    \define("PHP_INT_MIN", ~PHP_INT_MAX);
}

abstract class DriverTest extends TestCase
{
    public Driver $loop;

    /**
     * The DriverFactory to run this test on.
     *
     * @return callable
     */
    abstract public function getFactory(): callable;

    public function setUp(): void
    {
        $this->loop = ($this->getFactory())();
        \gc_collect_cycles();
    }

    public function tearDown(): void
    {
        unset($this->loop);
    }

    public function testCorrectTimeoutIfBlockingBeforeActivate(): void
    {
        $start = 0;
        $invoked = 0;

        $this->start(function (Driver $loop) use (&$start, &$invoked): void {
            $loop->defer(function () use ($loop, &$start, &$invoked) {
                $start = now();

                $loop->delay(1, function () use (&$invoked) {
                    $invoked = now();
                });

                \usleep(500000);
            });
        });

        self::assertNotSame(0, $start);
        self::assertNotSame(0, $invoked);

        self::assertGreaterThanOrEqual(1, $invoked - $start);
        self::assertLessThan(1.1, $invoked - $start);
    }

    public function testCorrectTimeoutIfBlockingBeforeDelay(): void
    {
        $start = 0;
        $invoked = 0;

        $this->start(function (Driver $loop) use (&$start, &$invoked): void {
            $start = now();

            \usleep(500000);

            $loop->delay(1, function () use (&$invoked) {
                $invoked = now();
            });
        });

        self::assertNotSame(0, $start);
        self::assertNotSame(0, $invoked);

        self::assertGreaterThanOrEqual(1.5, $invoked - $start);
        self::assertLessThan(1.6, $invoked - $start);
    }

    public function testLoopTerminatesWithOnlyUnreferencedWatchers(): void
    {
        $this->start(function (Driver $loop) use (&$end): void {
            $loop->unreference($loop->onReadable(STDIN, static function (): void {
            }));
            $w = $loop->delay(10, static function (): void {
            });
            $loop->defer(function () use ($loop, $w): void {
                $loop->cancel($w);
            });
            $end = true;
        });
        self::assertTrue($end);
    }

    /** This MUST NOT have a "test" prefix, otherwise it's executed as test and marked as risky. */
    public function checkForSignalCapability(): void
    {
        if (!\extension_loaded('posix')) {
            self::markTestSkipped("ext-posix is required for sending test signals. Skipping.");
        }

        try {
            $watcher = $this->loop->onSignal(SIGUSR1, function (): void {
            });
            $this->loop->cancel($watcher);
        } catch (UnsupportedFeatureException $e) {
            self::markTestSkipped("The loop is not capable of handling signals properly. Skipping.");
        }
    }

    public function testWatcherUnrefRerefRunResult(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->defer(function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
            $loop->unreference($watcher);
            $loop->reference($watcher);
        });
        self::assertTrue($invoked);
    }

    public function testDeferWatcherUnrefRunResult(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->defer(function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
        });
        self::assertFalse($invoked);
    }

    public function testOnceWatcherUnrefRunResult(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->delay(2, function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
            $loop->unreference($watcher);
        });
        self::assertFalse($invoked);
    }

    public function testRepeatWatcherUnrefRunResult(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->repeat(2, function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
        });
        self::assertFalse($invoked);
    }

    public function testOnReadableWatcherUnrefRunResult(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->onReadable(STDIN, function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
        });
        self::assertFalse($invoked);
    }

    public function testOnWritableWatcherKeepAliveRunResult(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->onWritable(STDOUT, function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
        });
        self::assertFalse($invoked);
    }

    public function testOnSignalWatcherKeepAliveRunResult(): void
    {
        $this->checkForSignalCapability();
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->onSignal(SIGUSR1, function () {
                // empty
            });
            $watcher = $loop->delay(0.1, function () use (&$invoked, $loop, $watcher): void {
                $invoked = true;
                $loop->unreference($watcher);
            });
            $loop->unreference($watcher);
        });
        self::assertTrue($invoked);
    }

    public function testUnreferencedDeferWatcherStillExecutes(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcher = $loop->defer(function () use (&$invoked): void {
                $invoked = true;
            });
            $loop->unreference($watcher);
            $loop->defer(function () {
                // just to keep loop running
            });
        });
        self::assertTrue($invoked);
    }

    public function testLoopDoesNotBlockOnNegativeTimerExpiration(): void
    {
        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked): void {
            $loop->delay(0.001, function () use (&$invoked): void {
                $invoked = true;
            });

            \usleep(1000 * 10);
        });
        self::assertTrue($invoked);
    }

    public function testDisabledDeferReenableInSubsequentTick(): void
    {
        $this->expectOutputString("123");
        $this->start(function (Driver $loop) {
            $watcherId = $loop->defer(function ($watcherId): void {
                echo 3;
            });
            $loop->disable($watcherId);
            $loop->defer(function () use ($loop, $watcherId): void {
                $loop->enable($watcherId);
                echo 2;
            });
            echo 1;
        });
    }

    public function provideRegistrationArgs(): array
    {
        $args = [
            [
                "defer",
                [
                    function () {
                    },
                ],
            ],
            [
                "delay",
                [
                    0.005,
                    function () {
                    },
                ],
            ],
            [
                "repeat",
                [
                    0.005,
                    function () {
                    },
                ],
            ],
            [
                "onWritable",
                [
                    \STDOUT,
                    function () {
                    },
                ],
            ],
            [
                "onReadable",
                [
                    \STDIN,
                    function () {
                    },
                ],
            ],
            [
                "onSignal",
                [
                    \SIGUSR1,
                    function () {
                    },
                ],
            ],
        ];

        return $args;
    }

    /** @dataProvider provideRegistrationArgs */
    public function testDisableWithConsecutiveCancel(string $type, array $args): void
    {
        if ($type === "onSignal") {
            $this->checkForSignalCapability();
        }

        $invoked = false;
        $this->start(function (Driver $loop) use (&$invoked, $type, $args): void {
            $func = [$loop, $type];
            $watcherId = $func(...$args);
            $loop->disable($watcherId);
            $loop->defer(function () use (&$invoked, $loop, $watcherId): void {
                $loop->cancel($watcherId);
                $invoked = true;
            });
            $this->assertFalse($invoked);
        });
        self::assertTrue($invoked);
    }

    /** @dataProvider provideRegistrationArgs */
    public function testWatcherReferenceInfo(string $type, array $args): void
    {
        if ($type === "onSignal") {
            $this->checkForSignalCapability();
        }

        $loop = $this->loop;

        $func = [$loop, $type];
        if (\substr($type, 0, 2) === "on") {
            $type = "on_" . \lcfirst(\substr($type, 2));
        }

        // being referenced is the default
        $watcherId1 = $func(...$args);
        $info = $loop->getInfo();
        $expected = ["enabled" => 1, "disabled" => 0];
        self::assertSame($expected, $info[$type]);
        $expected = ["referenced" => 1, "unreferenced" => 0];
        self::assertSame($expected, $info["enabled_watchers"]);

        // explicitly reference() even though it's the default setting
        $argsCopy = $args;
        $watcherId2 = \call_user_func_array($func, $argsCopy);
        $loop->reference($watcherId2);
        $loop->reference($watcherId2);
        $info = $loop->getInfo();
        $expected = ["enabled" => 2, "disabled" => 0];
        self::assertSame($expected, $info[$type]);
        $expected = ["referenced" => 2, "unreferenced" => 0];
        self::assertSame($expected, $info["enabled_watchers"]);

        // disabling a referenced watcher should decrement the referenced count
        $loop->disable($watcherId2);
        $loop->disable($watcherId2);
        $loop->disable($watcherId2);
        $info = $loop->getInfo();
        $expected = ["referenced" => 1, "unreferenced" => 0];
        self::assertSame($expected, $info["enabled_watchers"]);

        // enabling a referenced watcher should increment the referenced count
        $loop->enable($watcherId2);
        $loop->enable($watcherId2);
        $info = $loop->getInfo();
        $expected = ["referenced" => 2, "unreferenced" => 0];
        self::assertSame($expected, $info["enabled_watchers"]);

        // cancelling an referenced watcher should decrement the referenced count
        $loop->cancel($watcherId2);
        $info = $loop->getInfo();
        $expected = ["referenced" => 1, "unreferenced" => 0];
        self::assertSame($expected, $info["enabled_watchers"]);

        // unreference() should just increment unreferenced count
        $watcherId2 = $func(...$args);
        $loop->unreference($watcherId2);
        $info = $loop->getInfo();
        $expected = ["enabled" => 2, "disabled" => 0];
        self::assertSame($expected, $info[$type]);
        $expected = ["referenced" => 1, "unreferenced" => 1];
        self::assertSame($expected, $info["enabled_watchers"]);

        $loop->cancel($watcherId1);
        $loop->cancel($watcherId2);
    }

    /** @dataProvider provideRegistrationArgs */
    public function testWatcherRegistrationAndCancellationInfo(string $type, array $args): void
    {
        if ($type === "onSignal") {
            $this->checkForSignalCapability();
        }

        $loop = $this->loop;

        $func = [$loop, $type];
        if (\substr($type, 0, 2) === "on") {
            $type = "on_" . \lcfirst(\substr($type, 2));
        }

        $watcherId = $func(...$args);
        self::assertIsString($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 1, "disabled" => 0];
        self::assertSame($expected, $info[$type]);

        // invoke enable() on active watcher to ensure it has no side-effects
        $loop->enable($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 1, "disabled" => 0];
        self::assertSame($expected, $info[$type]);

        // invoke disable() twice to ensure it has no side-effects
        $loop->disable($watcherId);
        $loop->disable($watcherId);

        $info = $loop->getInfo();
        $expected = ["enabled" => 0, "disabled" => 1];
        self::assertSame($expected, $info[$type]);

        $loop->cancel($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 0, "disabled" => 0];
        self::assertSame($expected, $info[$type]);

        $watcherId = $func(...$args);
        $info = $loop->getInfo();
        $expected = ["enabled" => 1, "disabled" => 0];
        self::assertSame($expected, $info[$type]);

        $loop->disable($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 0, "disabled" => 1];
        self::assertSame($expected, $info[$type]);

        $loop->enable($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 1, "disabled" => 0];
        self::assertSame($expected, $info[$type]);

        $loop->cancel($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 0, "disabled" => 0];
        self::assertSame($expected, $info[$type]);

        $loop->disable($watcherId);
        $info = $loop->getInfo();
        $expected = ["enabled" => 0, "disabled" => 0];
        self::assertSame($expected, $info[$type]);
    }

    /**
     * @dataProvider provideRegistrationArgs
     * @group memoryleak
     */
    public function testNoMemoryLeak(string $type, array $args): void
    {
        if ($this->getTestResultObject()->getCollectCodeCoverageInformation()) {
            self::markTestSkipped("Cannot run this test with code coverage active [code coverage consumes memory which makes it impossible to rely on memory_get_usage()]");
        }

        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Skip on Windows for now, investigate');
        }

        $runs = 2000;

        if ($type === "onSignal") {
            $this->checkForSignalCapability();
        }

        $this->start(function (Driver $loop) use ($type, $args, $runs) {
            $initialMem = \memory_get_usage();
            $cb = function ($runs) use ($loop, $type, $args): void {
                $func = [$loop, $type];
                for ($watchers = [], $i = 0; $i < $runs; $i++) {
                    $watchers[] = $func(...$args);
                }
                foreach ($watchers as $watcher) {
                    $loop->cancel($watcher);
                }
                for ($watchers = [], $i = 0; $i < $runs; $i++) {
                    $watchers[] = $func(...$args);
                }
                foreach ($watchers as $watcher) {
                    $loop->disable($watcher);
                    $loop->cancel($watcher);
                }
                for ($watchers = [], $i = 0; $i < $runs; $i++) {
                    $watchers[] = $func(...$args);
                }
                if ($type === "repeat") {
                    $loop->delay(0.007, function () use ($loop, $watchers): void {
                        foreach ($watchers as $watcher) {
                            $loop->cancel($watcher);
                        }
                    });
                } elseif ($type !== "defer" && $type !== "delay") {
                    $loop->defer(function () use ($loop, $watchers) {
                        foreach ($watchers as $watcher) {
                            $loop->cancel($watcher);
                        }
                    });
                }
                $loop->run();
                if ($type === "defer") {
                    $loop->defer($fn = static function () use (&$fn, $loop, $runs): void {
                        static $i = null;

                        $i = $i ?? $runs;

                        if ($i--) {
                            $loop->defer($fn);
                        }
                    });
                    $loop->run();
                }
                if ($type === "delay") {
                    $loop->delay(0, $fn = static function () use (&$fn, $loop, $runs): void {
                        static $i = null;

                        $i = $i ?? $runs;

                        if ($i--) {
                            $loop->delay(0, $fn);
                        }
                    });
                    $loop->run();
                }
                if ($type === "repeat") {
                    $loop->repeat(0, $fn = static function ($watcherId) use (&$fn, $loop, $runs): void {
                        static $i = null;

                        $i = $i ?? $runs;

                        $loop->cancel($watcherId);
                        if ($i--) {
                            $loop->repeat(0, $fn);
                        }
                    });
                    $loop->run();
                }
                if ($type === "onWritable") {
                    $loop->defer(static function ($watcherId) use ($loop, $runs): void {
                        $fn = static function ($watcherId, $socket) use (&$fn, $loop, $runs): void {
                            static $i = null;

                            $i = $i ?? ($runs + 1);

                            $loop->cancel($watcherId);
                            if ($socket) {
                                \fwrite($socket, ".");
                            }

                            if ($i--) {
                                // explicitly use *different* streams with *different* resource ids
                                $ends = \stream_socket_pair(
                                    \stripos(
                                        PHP_OS,
                                        "win"
                                    ) === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
                                    STREAM_SOCK_STREAM,
                                    STREAM_IPPROTO_IP
                                );

                                $loop->onWritable($ends[0], $fn);
                                $loop->onReadable($ends[1], function ($watcherId) use ($loop): void {
                                    $loop->cancel($watcherId);
                                });
                            }
                        };

                        $fn($watcherId, null);
                    });
                    $loop->run();
                }
                if ($type === "onSignal") {
                    $sendSignal = function (): void {
                        \posix_kill(\getmypid(), \SIGUSR1);
                    };
                    $loop->onSignal(
                        \SIGUSR1,
                        $fn = static function ($watcherId) use (&$fn, $loop, $sendSignal, $runs): void {
                            static $i = null;

                            $i = $i ?? $runs;

                            if ($i--) {
                                $loop->onSignal(\SIGUSR1, $fn);
                                $loop->delay(0.001, $sendSignal);
                            }
                            $loop->cancel($watcherId);
                        }
                    );
                    $loop->delay(0.001, $sendSignal);
                    $loop->run();
                }
            };
            $closureMem = \memory_get_usage() - $initialMem;
            $cb($runs); /* just to set up eventual structures inside loop without counting towards memory comparison */
            \gc_collect_cycles();
            $initialMem = \memory_get_usage() - $closureMem;
            $cb($runs);
            unset($cb);

            \gc_collect_cycles();
            $endMem = \memory_get_usage();

            /* this is allowing some memory usage due to runtime caches etc., but nothing actually leaking */
            $this->assertLessThan($runs * 4, $endMem - $initialMem); // * 4, as 4 is minimal sizeof(void *)
        });
    }

    /**
     * The first number of each tuple indicates the tick in which the watcher is supposed to execute, the second digit
     * indicates the order within the tick.
     */
    public function testExecutionOrderGuarantees(): void
    {
        $this->expectOutputString("01 02 03 04 " . \str_repeat("05 ", 8) . "10 11 12 " . \str_repeat(
            "13 ",
            4
        ) . "20 " . \str_repeat("21 ", 4) . "30 40 41 ");
        $this->start(function (Driver $loop): void {
            // Wrap in extra defer, so driver creation time doesn't count for timers, as timers are driver creation
            // relative instead of last tick relative before first tick.
            $loop->defer(function () use ($loop): void {
                $f = function (...$args) use ($loop): callable {
                    return function ($watcherId) use ($loop, &$args): void {
                        if (!$args) {
                            $this->fail("Watcher callback called too often");
                        }
                        $loop->cancel($watcherId);
                        echo \array_shift($args) . \array_shift($args), " ";
                    };
                };

                $loop->onWritable(STDOUT, $f(0, 5));
                $writ1 = $loop->onWritable(STDOUT, $f(0, 5));
                $writ2 = $loop->onWritable(STDOUT, $f(0, 5));

                $loop->delay(0, $f(0, 5));
                $del1 = $loop->delay(0, $f(0, 5));
                $del2 = $loop->delay(0, $f(0, 5));
                $del3 = $loop->delay(0, $f());
                $del4 = $loop->delay(0, $f(1, 3));
                $del5 = $loop->delay(0, $f(2, 0));
                $loop->defer(function () use ($loop, $del5): void {
                    $loop->disable($del5);
                });
                $loop->cancel($del3);
                $loop->disable($del1);
                $loop->disable($del2);

                $writ3 = $loop->onWritable(STDOUT, $f());
                $loop->cancel($writ3);
                $loop->disable($writ1);
                $loop->disable($writ2);
                $loop->enable($writ1);
                $writ4 = $loop->onWritable(STDOUT, $f(1, 3));
                $loop->onWritable(STDOUT, $f(0, 5));
                $loop->enable($writ2);
                $loop->disable($writ4);
                $loop->defer(function () use ($loop, $writ4, $f): void {
                    $loop->enable($writ4);
                    $loop->onWritable(STDOUT, $f(1, 3));
                });

                $loop->enable($del1);
                $loop->delay(0, $f(0, 5));
                $loop->enable($del2);
                $loop->disable($del4);
                $loop->defer(function () use ($loop, $del4, $f): void {
                    $loop->enable($del4);
                    $loop->onWritable(STDOUT, $f(1, 3));
                });

                $loop->delay(1, $f(4, 1));
                $loop->delay(0.6, $f(3, 0));
                $loop->delay(0.5, $f(2, 1));
                $loop->repeat(0.5, $f(2, 1));
                $rep1 = $loop->repeat(0.25, $f(2, 1));
                $loop->disable($rep1);
                $loop->delay(0.5, $f(2, 1));
                $loop->enable($rep1);

                $loop->defer($f(0, 1));
                $def1 = $loop->defer($f(0, 3));
                $def2 = $loop->defer($f(1, 1));
                $def3 = $loop->defer($f());
                $loop->defer($f(0, 2));
                $loop->disable($def1);
                $loop->cancel($def3);
                $loop->enable($def1);
                $loop->defer(function () use ($loop, $def2, $del5, $f): void {
                    $tick = $f(0, 4);
                    $tick("invalid");
                    $loop->defer($f(1, 0));
                    $loop->enable($def2);
                    $loop->defer($f(1, 2));
                    $loop->defer(function () use ($loop, $del5, $f): void {
                        $loop->enable($del5);
                        $loop->defer(function () use ($loop, $f): void {
                            \usleep(700000); // to have delays of 0.5 and 0.6 run at the same tick (but not 0.15)
                            $loop->defer(function () use ($loop, $f): void {
                                $loop->defer($f(4, 0));
                            });
                        });
                    });
                });
                $loop->disable($def2);
            });
        });
    }

    public function testSignalExecutionOrder(): void
    {
        $this->checkForSignalCapability();

        $this->expectOutputString("122222");
        $this->start(function (Driver $loop): void {
            $f = function ($i) use ($loop) {
                return function ($watcherId) use ($loop, $i): void {
                    $loop->cancel($watcherId);
                    echo $i;
                };
            };

            $loop->defer($f(1));
            $loop->onSignal(SIGUSR1, $f(2));
            $sig1 = $loop->onSignal(SIGUSR1, $f(2));
            $sig2 = $loop->onSignal(SIGUSR1, $f(2));
            $sig3 = $loop->onSignal(SIGUSR1, $f(" FAIL - MUST NOT BE CALLED "));
            $loop->disable($sig1);
            $loop->onSignal(SIGUSR1, $f(2));
            $loop->disable($sig2);
            $loop->enable($sig1);
            $loop->cancel($sig3);
            $loop->onSignal(SIGUSR1, $f(2));
            $loop->defer(function () use ($loop, $sig2): void {
                $loop->enable($sig2);
                $loop->delay(0.001, function () use ($loop) {
                    \posix_kill(\getmypid(), \SIGUSR1);
                    $loop->delay(0.01, function () use ($loop): void {
                        $loop->stop();
                    });
                });
            });
        });
    }

    public function testExceptionOnEnableNonexistentWatcher(): void
    {
        $this->expectException(InvalidWatcherError::class);

        try {
            $this->loop->enable("nonexistentWatcher");
        } catch (InvalidWatcherError $e) {
            self::assertSame("nonexistentWatcher", $e->getWatcherId());
            throw $e;
        }
    }

    public function testSuccessOnDisableNonexistentWatcher(): void
    {
        $this->loop->disable("nonexistentWatcher");

        // Otherwise risky, throwing fails the test
        self::assertTrue(true);
    }

    public function testSuccessOnCancelNonexistentWatcher(): void
    {
        $this->loop->cancel("nonexistentWatcher");

        // Otherwise risky, throwing fails the test
        self::assertTrue(true);
    }

    public function testExceptionOnReferenceNonexistentWatcher(): void
    {
        $this->expectException(InvalidWatcherError::class);

        try {
            $this->loop->reference("nonexistentWatcher");
        } catch (InvalidWatcherError $e) {
            self::assertSame("nonexistentWatcher", $e->getWatcherId());
            throw $e;
        }
    }

    public function testSuccessOnUnreferenceNonexistentWatcher(): void
    {
        $this->loop->unreference("nonexistentWatcher");

        // Otherwise risky, throwing fails the test
        self::assertTrue(true);
    }

    public function testWatcherInvalidityOnDefer(): void
    {
        $this->expectException(InvalidWatcherError::class);

        $this->start(function (Driver $loop): void {
            $loop->defer(function ($watcher) use ($loop): void {
                $loop->enable($watcher);
            });
        });
    }

    public function testWatcherInvalidityOnDelay(): void
    {
        $this->expectException(InvalidWatcherError::class);

        $this->start(function (Driver $loop): void {
            $loop->delay(0, function ($watcher) use ($loop): void {
                $loop->enable($watcher);
            });
        });
    }

    public function testEventsNotExecutedInSameTickAsEnabled(): void
    {
        $this->start(function (Driver $loop): void {
            $loop->defer(function () use ($loop): void {
                $loop->defer(function () use ($loop, &$diswatchers, &$watchers): void {
                    $loop->defer(function () use ($loop, $diswatchers): void {
                        foreach ($diswatchers as $watcher) {
                            $loop->disable($watcher);
                        }
                        $loop->defer(function () use ($loop, $diswatchers): void {
                            $loop->defer(function () use ($loop, $diswatchers): void {
                                foreach ($diswatchers as $watcher) {
                                    $loop->cancel($watcher);
                                }
                            });
                            foreach ($diswatchers as $watcher) {
                                $loop->enable($watcher);
                            }
                        });
                    });
                    foreach ($watchers as $watcher) {
                        $loop->cancel($watcher);
                    }
                    foreach ($diswatchers as $watcher) {
                        $loop->disable($watcher);
                        $loop->enable($watcher);
                    }
                });

                $f = function () use ($loop): array {
                    $watchers[] = $loop->defer([$this, "fail"]);
                    $watchers[] = $loop->delay(0, [$this, "fail"]);
                    $watchers[] = $loop->repeat(0, [$this, "fail"]);
                    $watchers[] = $loop->onWritable(STDIN, [$this, "fail"]);
                    return $watchers;
                };
                $watchers = $f();
                $diswatchers = $f();
            });
        });

        // Otherwise risky, as we only rely on $this->fail()
        self::assertTrue(true);
    }

    public function testEnablingWatcherAllowsSubsequentInvocation(): void
    {
        $increment = 0;
        $watcherId = $this->loop->defer(function () use (&$increment): void {
            $increment++;
        });
        $this->loop->disable($watcherId);
        $this->loop->delay(0.005, [$this->loop, "stop"]);
        $this->loop->run();
        self::assertSame(0, $increment);
        $this->loop->enable($watcherId);
        $this->loop->delay(0.005, [$this->loop, "stop"]);
        $this->loop->run();
        self::assertSame(1, $increment);
    }

    public function testUnresolvedEventsAreReenabledOnRunFollowingPreviousStop(): void
    {
        $increment = 0;
        $this->start(function (Driver $loop) use (&$increment): void {
            $loop->defer([$loop, "stop"]);
            $loop->run();

            $loop->defer(function () use (&$increment, $loop): void {
                $loop->delay(0.1, function () use ($loop, &$increment): void {
                    $increment++;
                    $loop->stop();
                });
            });

            $this->assertSame(0, $increment);
            \usleep(5000);
        });
        self::assertSame(1, $increment);
    }

    public function testTimerWatcherParameterOrder(): void
    {
        $this->start(function (Driver $loop): void {
            $counter = 0;
            $loop->defer(function ($watcherId) use ($loop, &$counter): void {
                $this->assertIsString($watcherId);
                if (++$counter === 3) {
                    $loop->stop();
                }
            });
            $loop->delay(0.005, function ($watcherId) use ($loop, &$counter): void {
                $this->assertIsString($watcherId);
                if (++$counter === 3) {
                    $loop->stop();
                }
            });
            $loop->repeat(0.005, function ($watcherId) use ($loop, &$counter): void {
                $this->assertIsString($watcherId);
                $loop->cancel($watcherId);
                if (++$counter === 3) {
                    $loop->stop();
                }
            });
        });
    }

    public function testStreamWatcherParameterOrder(): void
    {
        $this->start(function (Driver $loop) use (&$invoked): void {
            $invoked = 0;
            $loop->onWritable(STDOUT, function ($watcherId, $stream) use ($loop, &$invoked): void {
                $this->assertIsString($watcherId);
                $this->assertSame(STDOUT, $stream);
                $invoked++;
                $loop->cancel($watcherId);
            });
        });
        self::assertSame(1, $invoked);
    }

    public function testDisablingWatcherPreventsSubsequentInvocation(): void
    {
        $this->start(function (Driver $loop): void {
            $increment = 0;
            $watcherId = $loop->defer(function () use (&$increment): void {
                $increment++;
            });

            $loop->disable($watcherId);
            $loop->delay(0.005, [$loop, "stop"]);

            $this->assertSame(0, $increment);
        });
    }

    public function testImmediateExecution(): void
    {
        $increment = 0;
        $this->start(function (Driver $loop) use (&$increment): void {
            $loop->defer(function () use (&$increment): void {
                $increment++;
            });
            $loop->defer([$loop, "stop"]);
        });
        self::assertSame(1, $increment);
    }

    public function testImmediatelyCallbacksDoNotRecurseInSameTick(): void
    {
        $increment = 0;
        $this->start(function (Driver $loop) use (&$increment): void {
            $loop->defer(function () use ($loop, &$increment) {
                $increment++;
                $loop->defer(function () use (&$increment) {
                    $increment++;
                });
            });
            $loop->defer([$loop, "stop"]);
        });
        self::assertSame(1, $increment);
    }

    public function testRunExecutesEventsUntilExplicitlyStopped(): void
    {
        $increment = 0;
        $this->start(function (Driver $loop) use (&$increment): void {
            $loop->repeat(0.005, function ($watcherId) use ($loop, &$increment): void {
                $increment++;
                if ($increment === 10) {
                    $loop->cancel($watcherId);
                }
            });
        });
        self::assertSame(10, $increment);
    }

    public function testLoopAllowsExceptionToBubbleUpDuringStart(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("loop error");

        $this->start(function (Driver $loop): void {
            $loop->defer(function (): void {
                throw new \Exception("loop error");
            });
        });
    }

    public function testLoopAllowsExceptionToBubbleUpFromRepeatingAlarmDuringStart(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("test");

        $this->start(function (Driver $loop): void {
            $loop->repeat(0.001, function (): void {
                throw new \RuntimeException("test");
            });
        });
    }

    public function testErrorHandlerCapturesUncaughtException(): void
    {
        $msg = "";
        $this->loop->setErrorHandler($f = function (): void {
        });
        $oldErrorHandler = $this->loop->setErrorHandler(function (\Exception $error) use (&$msg): void {
            $msg = $error->getMessage();
        });
        self::assertSame($f, $oldErrorHandler);
        $this->start(function (Driver $loop) {
            $loop->defer(function () {
                throw new \Exception("loop error");
            });
        });
        self::assertSame("loop error", $msg);
    }

    public function testOnErrorFailure(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("errorception");

        $this->loop->setErrorHandler(function (): void {
            throw new \Exception("errorception");
        });
        $this->start(function (Driver $loop): void {
            $loop->delay(0.005, function () {
                throw new \Exception("error");
            });
        });
    }

    public function testLoopException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("test");

        $this->start(function (Driver $loop): void {
            $loop->defer(function () use ($loop): void {
                // force next tick, outside of primary startup tick
                $loop->defer(function () {
                    throw new \RuntimeException("test");
                });
            });
        });
    }

    public function testOnSignalWatcher(): void
    {
        $this->checkForSignalCapability();

        $this->expectOutputString("caught SIGUSR1");
        $this->start(function (Driver $loop): void {
            $loop->delay(0.001, function () use ($loop): void {
                \posix_kill(\getmypid(), \SIGUSR1);
                $loop->delay(0.01, [$loop, "stop"]);
            });

            $loop->onSignal(SIGUSR1, function ($watcherId) use ($loop): void {
                $loop->cancel($watcherId);
                echo "caught SIGUSR1";
            });
        });
    }

    public function testInitiallyDisabledOnSignalWatcher(): void
    {
        $this->checkForSignalCapability();

        $this->expectOutputString("caught SIGUSR1");
        $this->start(function (Driver $loop): void {
            $stop = $loop->delay(0.1, function () use ($loop): void {
                echo "ERROR: manual stop";
                $loop->stop();
            });
            $watcherId = $loop->onSignal(SIGUSR1, function ($watcherId) use ($loop, $stop): void {
                echo "caught SIGUSR1";
                $loop->disable($stop);
                $loop->disable($watcherId);
            });
            $loop->disable($watcherId);

            $loop->delay(0.001, function () use ($loop, $watcherId): void {
                $loop->enable($watcherId);
                $loop->delay(0.001, function () {
                    \posix_kill(\getmypid(), SIGUSR1);
                });
            });
        });
    }

    public function testNestedLoopSignalDispatch(): void
    {
        $this->checkForSignalCapability();

        $this->expectOutputString("inner SIGUSR2\nouter SIGUSR1\n");
        $this->start(function (Driver $loop): void {
            $loop->delay(0.3, function () use ($loop): void {
                $loop->stop();
            });
            $loop->onSignal(SIGUSR1, function () use ($loop): void {
                echo "outer SIGUSR1\n";
                $loop->stop();
            });

            $loop->delay(0.001, function (): void {
                /** @var Driver $loop */
                $loop = ($this->getFactory())();
                $stop = $loop->delay(0.1, function () use ($loop): void {
                    echo "ERROR: manual stop";
                    $loop->stop();
                });
                $loop->onSignal(SIGUSR2, function ($watcherId) use ($loop, $stop): void {
                    echo "inner SIGUSR2\n";
                    $loop->cancel($stop);
                    $loop->cancel($watcherId);
                });
                $loop->delay(0.001, function (): void {
                    \posix_kill(\getmypid(), SIGUSR2);
                });
                $loop->run();
            });

            $loop->delay(0.02, function (): void {
                \posix_kill(\getmypid(), \SIGUSR1);
            });
        });
    }

    public function testCancelRemovesWatcher(): void
    {
        $invoked = false;

        $this->start(function (Driver $loop) use (&$invoked): void {
            $watcherId = $loop->delay(0.01, function (): void {
                $this->fail('Watcher was not cancelled as expected');
            });

            $loop->defer(function () use ($loop, $watcherId, &$invoked): void {
                $loop->cancel($watcherId);
                $invoked = true;
            });

            $loop->delay(0.005, [$loop, "stop"]);
        });

        self::assertTrue($invoked);
    }

    public function testOnWritableWatcher(): void
    {
        $flag = false;
        $this->start(function (Driver $loop) use (&$flag): void {
            $loop->onWritable(STDOUT, function () use ($loop, &$flag) {
                $flag = true;
                $loop->stop();
            });
            $loop->delay(0.005, [$loop, "stop"]);
        });
        self::assertTrue($flag);
    }

    public function testInitiallyDisabledWriteWatcher(): void
    {
        $increment = 0;
        $this->start(function (Driver $loop): void {
            $watcherId = $loop->onWritable(STDOUT, function () use (&$increment): void {
                $increment++;
            });
            $loop->disable($watcherId);
            $loop->delay(0.005, [$loop, "stop"]);
        });
        self::assertSame(0, $increment);
    }

    public function testInitiallyDisabledWriteWatcherIsTriggeredOnceEnabled(): void
    {
        $this->expectOutputString("12");
        $this->start(function (Driver $loop): void {
            $watcherId = $loop->onWritable(STDOUT, function () use ($loop): void {
                echo 2;
                $loop->stop();
            });
            $loop->disable($watcherId);
            $loop->defer(function () use ($loop, $watcherId): void {
                $loop->enable($watcherId);
                echo 1;
            });
        });
    }

    public function testStreamWatcherDoesntSwallowExceptions(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->start(function (Driver $loop): void {
            $loop->onWritable(STDOUT, function () {
                throw new \RuntimeException();
            });
            $loop->delay(0.005, [$loop, "stop"]);
        });
    }

    public function testReactorRunsUntilNoWatchersRemain(): void
    {
        $var1 = $var2 = 0;
        $this->start(function (Driver $loop) use (&$var1, &$var2): void {
            $loop->repeat(0.001, function ($watcherId) use ($loop, &$var1): void {
                if (++$var1 === 3) {
                    $loop->cancel($watcherId);
                }
            });

            $loop->onWritable(STDOUT, function ($watcherId) use ($loop, &$var2): void {
                if (++$var2 === 4) {
                    $loop->cancel($watcherId);
                }
            });
        });
        self::assertSame(3, $var1);
        self::assertSame(4, $var2);
    }

    public function testReactorRunsUntilNoWatchersRemainWhenStartedDeferred(): void
    {
        $var1 = $var2 = 0;
        $this->start(function (Driver $loop) use (&$var1, &$var2): void {
            $loop->defer(function () use ($loop, &$var1, &$var2): void {
                $loop->repeat(0.001, function ($watcherId) use ($loop, &$var1): void {
                    if (++$var1 === 3) {
                        $loop->cancel($watcherId);
                    }
                });

                $loop->onWritable(STDOUT, function ($watcherId) use ($loop, &$var2): void {
                    if (++$var2 === 4) {
                        $loop->cancel($watcherId);
                    }
                });
            });
        });
        self::assertSame(3, $var1);
        self::assertSame(4, $var2);
    }

    public function testOptionalCallbackDataPassedOnInvocation(): void
    {
        $callbackData = new \StdClass();

        $this->start(function (Driver $loop) use ($callbackData): void {
            $loop->defer(function ($watcherId) use ($callbackData): void {
                $callbackData->defer = true;
            });
            $loop->delay(0.001, function ($watcherId) use ($callbackData): void {
                $callbackData->delay = true;
            });
            $loop->repeat(0.001, function ($watcherId) use ($loop, $callbackData): void {
                $callbackData->repeat = true;
                $loop->cancel($watcherId);
            });
            $loop->onWritable(STDERR, function ($watcherId, $stream) use ($loop, $callbackData): void {
                $callbackData->onWritable = true;
                $loop->cancel($watcherId);
            });
        });

        self::assertTrue($callbackData->defer);
        self::assertTrue($callbackData->delay);
        self::assertTrue($callbackData->repeat);
        self::assertTrue($callbackData->onWritable);
    }

    public function testLoopStopPreventsTimerExecution(): void
    {
        $t = \microtime(1);
        $this->start(function (Driver $loop): void {
            $loop->defer(function () use ($loop): void {
                $loop->delay(1, function (): void {
                    $this->fail("Timer was executed despite stopped loop");
                });
            });
            $loop->defer([$loop, "stop"]);
        });
        self::assertGreaterThan(\microtime(1), $t + 0.1);
    }

    public function testDeferEnabledInNextTick(): void
    {
        $tick = function () {
            $this->loop->defer([$this->loop, "stop"]);
            $this->loop->run();
        };

        $invoked = 0;

        $watcher = $this->loop->onWritable(STDOUT, function () use (&$invoked): void {
            $invoked++;
        });

        $tick();
        $tick();
        $tick();

        $this->loop->disable($watcher);
        $this->loop->enable($watcher);
        $tick(); // disable + immediate enable after a tick should have no effect either

        self::assertSame(4, $invoked);
    }

    public function testMicrotaskExecutedImmediatelyAfterWatcher(): void
    {
        self::expectOutputString('12835674');

        $this->loop->queue(function (): void {
            print 1;
        });

        $this->start(function (Driver $loop): void {
            $loop->queue(function (): void {
                print 2;
            });

            $loop->defer(function () use ($loop): void {
                print 3;

                $loop->defer(function (): void {
                    print 4;
                });

                $loop->queue(function (): void {
                    print 5;
                });

                $loop->queue(function (): void {
                    print 6;
                });
            });

            $loop->defer(function (): void {
                print 7;
            });

            $loop->queue(function (): void {
                print 8;
            });
        });
    }

    public function testMicrotaskThrowingStillExecutesNextMicrotask(): void
    {
        $exception = new \Exception();
        $invoked = false;

        try {
            $this->start(function (Driver $loop) use (&$invoked, $exception): void {
                $loop->queue(function () use ($exception): void {
                    throw $exception;
                });

                $loop->queue(function () use (&$invoked): void {
                    $invoked = true;
                });
            });
        } catch (\Exception $e) {
            self::assertSame($exception, $e);
        }

        $this->start(fn () => null);

        self::assertTrue($invoked);
    }

    public function testRethrowsFromCallbacks(): void
    {
        foreach (["onReadable", "onWritable", "defer", "delay", "repeat", "onSignal"] as $watcher) {
            if ($watcher === "onSignal") {
                $this->checkForSignalCapability();
            }

            try {
                $args = [];

                switch ($watcher) {
                    case "onSignal":
                        $args[] = SIGUSR1;
                        break;

                    case "onWritable":
                        $args[] = STDOUT;
                        break;

                    case "onReadable":
                        $ends = \stream_socket_pair(
                            \stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
                            STREAM_SOCK_STREAM,
                            STREAM_IPPROTO_IP
                        );
                        \fwrite($ends[0], "trigger readability watcher");
                        $args[] = $ends[1];
                        break;

                    case "delay":
                    case "repeat":
                        $args[] = 0.005;
                        break;
                }

                $args[] = function ($watcherId) {
                    $this->loop->cancel($watcherId);
                    throw new \Exception("rethrow test");
                };

                [$this->loop, $watcher](...$args);

                if ($watcher === "onSignal") {
                    $this->loop->delay(0.1, function () {
                        \posix_kill(\getmypid(), \SIGUSR1);
                    });
                }

                $this->loop->run();

                self::fail("Didn't throw expected exception.");
            } catch (\Exception $e) {
                self::assertSame("rethrow test", $e->getMessage());
            }
        }
    }

    public function testMultipleWatchersOnSameDescriptor(): void
    {
        $sockets = \stream_socket_pair(
            \stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        \fwrite($sockets[1], "testing");

        $invoked = 0;
        $watcher1 = $this->loop->onReadable($sockets[0], function ($watcher) use (&$invoked): void {
            $invoked += 1;
            $this->loop->disable($watcher);
        });
        $watcher2 = $this->loop->onReadable($sockets[0], function ($watcher) use (&$invoked): void {
            $invoked += 10;
            $this->loop->disable($watcher);
        });
        $watcher3 = $this->loop->onWritable($sockets[0], function ($watcher) use (&$invoked): void {
            $invoked += 100;
            $this->loop->disable($watcher);
        });
        $watcher4 = $this->loop->onWritable($sockets[0], function ($watcher) use (&$invoked): void {
            $invoked += 1000;
            $this->loop->disable($watcher);
        });

        $this->loop->defer(function () use ($watcher1, $watcher3): void {
            $this->loop->delay(0.2, function () use ($watcher1, $watcher3): void {
                $this->loop->enable($watcher1);
                $this->loop->enable($watcher3);
            });
        });

        $this->loop->run();

        self::assertSame(1212, $invoked);

        $this->loop->enable($watcher1);
        $this->loop->enable($watcher3);

        $this->loop->delay(0.1, function () use ($watcher2, $watcher4) {
            $this->loop->enable($watcher2);
            $this->loop->enable($watcher4);
        });

        $this->loop->run();

        self::assertSame(2323, $invoked);
    }

    public function testStreamWritableIfConnectFails(): void
    {
        // first verify the operating system actually refuses the connection and no firewall is in place
        // use higher timeout because Windows retires multiple times and has a noticeable delay
        // @link https://stackoverflow.com/questions/19440364/why-do-failed-attempts-of-socket-connect-take-1-sec-on-windows
        $errno = $errstr = null;
        if (
            @\stream_socket_client('127.0.0.1:1', $errno, $errstr, 10) !== false
            || (\defined('SOCKET_ECONNREFUSED') && $errno !== \SOCKET_ECONNREFUSED)
        ) {
            self::markTestSkipped('Expected host to refuse connection, but got error ' . $errno . ': ' . $errstr);
        }

        $connecting = \stream_socket_client(
            '127.0.0.1:1',
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        $called = 0;
        $writeWatcher = $this->loop->onWritable($connecting, function (string $watcher) use (&$called) {
            ++$called;

            $this->loop->cancel($watcher);
        });

        $this->loop->unreference($this->loop->delay(10, function () use ($writeWatcher) {
            $this->loop->cancel($writeWatcher);
        }));

        $this->loop->run();

        self::assertEquals(1, $called);
    }

    public function testTimerIntervalCountedWhenNotRunning(): void
    {
        $this->loop->delay(1, function () use (&$start): void {
            $this->assertLessThan(0.5, \microtime(true) - $start);
        });

        \usleep(600000); // 600ms instead of 500ms to allow for variations in timing.
        $start = \microtime(true);
        $this->loop->run();
    }

    public function testShortTimerDoesNotBlockOtherTimers(): void
    {
        $this->loop->repeat(0, function (): void {
            static $i = 0;

            if (++$i === 5) {
                $this->fail("Loop continues with repeat watcher");
            }

            \usleep(2000);
        });

        $this->loop->delay(0.002, function (): void {
            $this->assertTrue(true);
            $this->loop->stop();
        });

        $this->loop->run();
    }

    public function testTwoShortRepeatTimersWorkAsExpected(): void
    {
        $this->loop->repeat(0, function () use (&$j): void {
            static $i = 0;
            if (++$i === 5) {
                $this->loop->stop();
            }
            $j = $i;
        });
        $this->loop->repeat(0, function () use (&$k): void {
            static $i = 0;
            if (++$i === 5) {
                $this->loop->stop();
            }
            $k = $i;
        });

        $this->loop->run();
        self::assertLessThan(2, \abs($j - $k));
        self::assertNotSame(0, $j);
    }

    protected function start($cb): void
    {
        $cb($this->loop);
        $this->loop->run();
    }
}
