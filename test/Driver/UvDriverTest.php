<?php

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
}
