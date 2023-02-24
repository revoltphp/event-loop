<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Driver;

class StreamSelectDriverTest extends DriverTest
{
    public function getFactory(): callable
    {
        return static function () {
            return new StreamSelectDriver();
        };
    }

    public function testHandle(): void
    {
        self::assertNull($this->loop->getHandle());
    }

    /**
     * @requires PHP 7.1
     */
    public function testAsyncSignals(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Skip on Windows');
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

    public function testTooLargeFileDescriptorSet(): void
    {
        if (\stripos(PHP_OS, 'win') === 0) {
            // win FD_SETSIZE not 1024
            self::markTestSkipped('Skip on Windows');
        }

        $sockets = [];

        for ($i = 0; $i < 1001; $i++) {
            $sockets[] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("You have reached the limits of stream_select(). It has a FD_SETSIZE of 1024, but you have file descriptors numbered at least as high as 2");

        try {
            $this->start(function (Driver $loop) use ($sockets) {
                $loop->delay(0.1, function () {
                    // here to provide timeout to stream_select, as the warning is only issued after the system call returns
                });

                foreach ($sockets as [$left, $right]) {
                    $loop->onReadable($left, function () {
                        // nothing
                    });

                    $loop->onReadable($right, function () {
                        // nothing
                    });
                }
            });
        } finally {
            foreach ($sockets as [$left, $right]) {
                \fclose($left);
                \fclose($right);
            }
        }
    }

    /**
     * @requires extension pcntl
     */
    public function testSignalDuringStreamSelectIgnored(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Skip on Windows');
        }

        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->start(function (Driver $loop) use ($sockets, &$signalCallbackId) {
            $socketCallbackIds = [
                $loop->onReadable($sockets[0], function () {
                    // nothing
                }),
                $loop->onReadable($sockets[1], function () {
                    // nothing
                }),
            ];

            $signalCallbackId = $loop->onSignal(\SIGUSR2, function ($callbackId) use ($socketCallbackIds, $loop) {
                $loop->cancel($callbackId);

                foreach ($socketCallbackIds as $socketCallbackId) {
                    $loop->cancel($socketCallbackId);
                }

                $this->assertTrue(true);
            });

            $loop->delay(0.1, function () {
                \proc_open('sh -c "sleep 1; kill -USR2 ' . \getmypid() . '"', [], $pipes);
            });
        });

        $this->loop->cancel($signalCallbackId);
    }
}
