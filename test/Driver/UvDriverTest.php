<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

/**
 * @requires extension uv
 */
class UvDriverTest extends DriverTest
{
    public function getFactory(): callable
    {
        return static function () {
            return new UvDriver();
        };
    }

    public function testHandle(): void
    {
        $handle = $this->loop->getHandle();
        self::assertTrue(\is_resource($handle) || $handle instanceof \UVLoop);
    }

    public function testSupported(): void
    {
        self::assertTrue(UvDriver::isSupported());
    }

    /**
     * Short timers with another referenced watcher: on libuv < 1.45, a timer due when the driver entered its
     * blocking run left the loop blocked forever. Run in a child process, killed if it hangs.
     */
    public function testShortTimersWithASignalWatcher(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $loop = new Revolt\EventLoop\Driver\UvDriver();
            Revolt\EventLoop::setDriver($loop);
            Revolt\EventLoop::onSignal(\defined('SIGUSR2') ? SIGUSR2 : 31, static fn () => null);
            for ($i = 0; $i < 3000; $i++) {
                $suspension = Revolt\EventLoop::getSuspension();
                Revolt\EventLoop::delay(0.001, $suspension->resume(...));
                $suspension->suspend();
            }
            echo 'done';
            PHP;

        $process = \proc_open(
            [\PHP_BINARY, '-r', $code, '--', __DIR__ . '/../../vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $deadline = \hrtime(true) + 30_000_000_000;
        while (\proc_get_status($process)['running'] && \hrtime(true) < $deadline) {
            \usleep(50_000);
        }
        $running = \proc_get_status($process)['running'];
        if ($running) {
            \proc_terminate($process, 9);
        }
        $output = \stream_get_contents($pipes[1]) . \stream_get_contents($pipes[2]);
        \proc_close($process);

        self::assertFalse($running, "3000 delays of 1 ms didn't complete in 30 seconds: $output");
        self::assertSame('done', $output);
    }
}
