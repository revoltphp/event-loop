<?php

namespace Revolt\EventLoop\Driver;

/**
 * @requires extension ev
 */
class EvDriverTest extends DriverTest
{
    public function getFactory(): callable
    {
        return static function () {
            return new EvDriver();
        };
    }

    public function testHandle(): void
    {
        self::assertInstanceOf(\EvLoop::class, $this->loop->getHandle());
    }

    public function testSupported(): void
    {
        self::assertTrue(EvDriver::isSupported());
    }
}
