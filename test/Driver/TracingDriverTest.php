<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

class TracingDriverTest extends DriverTest
{
    public function getFactory(): callable
    {
        return static function (): TracingDriver {
            return new TracingDriver(new StreamSelectDriver());
        };
    }

    /**
     * @dataProvider provideRegistrationArgs
     * @group memoryleak
     */
    public function testNoMemoryLeak(string $type, array $args): void
    {
        // Skip, because the driver intentionally leaks
        self::assertTrue(true);
    }
}
