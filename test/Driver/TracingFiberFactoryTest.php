<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\TracingFiberFactory;

class TracingFiberFactoryTest extends StreamSelectDriverTest
{
    private static TracingFiberFactory $factory;
    public function getFactory(): callable
    {
        self::$factory ??= new TracingFiberFactory;
        return static function (): StreamSelectDriver {
            return new StreamSelectDriver(self::$factory);
        };
    }

    public function testNumberOfFibers(): void
    {
        self::assertEquals(2, self::$factory->count());
        $this->start(static function (Driver $loop): void {
            $loop->queue(static function () use ($loop) {
                $suspension = $loop->getSuspension();
                $loop->delay(1, $suspension->resume(...));
                $suspension->suspend();
            });
            $loop->delay(0.5, function () {
                self::assertEquals(3, self::$factory->count());
            });
        });
        self::assertEquals(2, self::$factory->count());
    }
}
