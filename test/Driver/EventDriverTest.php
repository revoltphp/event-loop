<?php

declare(strict_types=1);

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

    public function testRefusedStreamDoesNotSpin(): void
    {
        self::markTestSkipped('libevent refuses to watch devices such as /dev/null, epoll rejects them with EPERM');
    }

    public function testEmptyRegularFileIsReadable(): void
    {
        self::markTestSkipped('libevent refuses to watch regular files, epoll rejects them with EPERM');
    }
}
