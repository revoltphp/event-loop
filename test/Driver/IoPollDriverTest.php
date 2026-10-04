<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Io\Poll\Context;
use Revolt\EventLoop\Driver;
use Time\Duration;

class IoPollDriverTest extends DriverTest
{
    public function getFactory(): callable
    {
        return static function () {
            return new IoPollDriver();
        };
    }

    public function setUp(): void
    {
        // Not isSupported(): that is deliberately false below 8.6, while the driver still has to be
        // exercised there through the polyfill.
        if (!\class_exists(Context::class) || !\class_exists(Duration::class)) {
            self::markTestSkipped("Skip, the Io\\Poll API requires PHP 8.6 or symfony/polyfill-io-poll");
        }

        parent::setUp();
    }

    public function testHandle(): void
    {
        self::assertInstanceOf(Context::class, $this->loop->getHandle());
    }

    public function testAsyncSignals(): void
    {
        if (self::isWindows()) {
            self::markTestSkipped('Skip on Windows');
        }

        if (!\extension_loaded("pcntl")
            || !\function_exists('pcntl_signal_dispatch')
            || !\function_exists('pcntl_signal')) {
            self::markTestSkipped('Skip, PCNTL functions not available');
        }

        \pcntl_async_signals(true);

        try {
            $this->start(function (Driver $loop) use (&$invoked, &$callbackId) {
                $callbackId = $loop->onSignal(SIGUSR1, function () use (&$invoked) {
                    $invoked = true;
                });

                $loop->defer(function () use ($loop, $callbackId) {
                    \posix_kill(\getmypid(), \SIGUSR1);

                    // Two defers, because defer is queued in the first tick and signals only after signals have been
                    // processed, so the second tick dispatches the signal. At the start of the third tick, we're done!
                    $loop->defer(function () use ($loop, $callbackId) {
                        $loop->defer(function () use ($loop, $callbackId) {
                            $loop->cancel($callbackId);
                        });
                    });
                });
            });
        } finally {
            \pcntl_async_signals(false);
        }

        self::assertTrue($invoked);

        $this->loop->cancel($callbackId);
    }

    /**
     * A signal arriving while the loop is blocked in wait() makes it throw ERROR_INTERRUPTED. The
     * driver has to turn that into the signal callback running, not into an exception.
     *
     * @requires extension pcntl
     */
    public function testSignalInterruptingWaitIsDispatched(): void
    {
        if (self::isWindows()) {
            self::markTestSkipped('Skip on Windows');
        }

        if (!\extension_loaded("pcntl")
            || !\function_exists('pcntl_signal_dispatch')
            || !\function_exists('pcntl_signal')
            || !\function_exists('pcntl_alarm')
        ) {
            self::markTestSkipped('Skip, PCNTL functions not available');
        }

        [$left, $right] = self::createSocketPair();
        $invoked = false;

        $this->start(function (Driver $loop) use ($left, &$invoked): void {
            // keeps the loop blocked in wait() until the kernel delivers SIGALRM a second later
            $readableId = $loop->onReadable($left, static function (): void {
                // nothing
            });

            $loop->onSignal(\SIGALRM, function (string $callbackId) use ($loop, $readableId, &$invoked): void {
                $invoked = true;
                $loop->cancel($callbackId);
                $loop->cancel($readableId);
            });

            \pcntl_alarm(1);
        });

        \fclose($left);
        \fclose($right);

        self::assertTrue($invoked);
    }

    /**
     * stream_select() is capped at FD_SETSIZE, which is 1024 on Linux. Poll backends are not.
     */
    public function testMoreFileDescriptorsThanFdSetSize(): void
    {
        if (self::isWindows()) {
            self::markTestSkipped('Skip on Windows');
        }

        if (!(new \ReflectionClass(Context::class))->isInternal()) {
            self::markTestSkipped('Skip, the polyfill is backed by stream_select() and capped at FD_SETSIZE too');
        }

        $sockets = [];

        for ($i = 0; $i < 700; $i++) {
            $sockets[] = self::createSocketPair();
        }

        $invoked = false;

        try {
            $this->start(function (Driver $loop) use ($sockets, &$invoked) {
                foreach ($sockets as [$left, $right]) {
                    $loop->onReadable($left, static function () {
                        // nothing
                    });

                    $loop->onReadable($right, static function () {
                        // nothing
                    });
                }

                [$left, $right] = \end($sockets);
                \fwrite($left, ".");

                $loop->onReadable($right, function (string $callbackId) use ($loop, &$invoked) {
                    $invoked = true;
                    $loop->stop();
                });

                $loop->delay(1, function () use ($loop) {
                    $loop->stop();
                });
            });
        } finally {
            foreach ($sockets as [$left, $right]) {
                \fclose($left);
                \fclose($right);
            }
        }

        self::assertTrue($invoked);
    }

    public function testSupportedOnlyWhenNative(): void
    {
        // the polyfill runs the driver, but it is backed by stream_select() and slower than using it directly
        self::assertSame(\PHP_VERSION_ID >= 80600, IoPollDriver::isSupported());
    }
}
