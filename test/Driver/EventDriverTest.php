<?php

namespace Revolt\EventLoop\Driver;

/**
 * @requires extension event
 */
class EventDriverTest extends DriverTest
{
    public function getFactory(): callable
    {
        return static function () {
            return new EventDriver();
        };
    }

    public function testHandle(): void
    {
        self::assertInstanceOf(\EventBase::class, $this->loop->getHandle());
    }

    public function testSupported(): void
    {
        self::assertTrue(EventDriver::isSupported());
    }
}
