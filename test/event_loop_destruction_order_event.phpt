--TEST--
Issue #105: Ensure the callback fiber is always alive as long as the event loop lives (event driver)
--SKIPIF--
<?php

if (!\extension_loaded('event')) {
    echo 'skip event extension required';
}

?>
--FILE--
<?php

use Revolt\EventLoop;
use Revolt\EventLoop\Driver\EventDriver;

require 'vendor/autoload.php';

EventLoop::setDriver(new EventDriver());

final class a {
    private static self $a;
    public static function getInstance(): self {
        return self::$a ??= new self;
    }

    public function __destruct()
    {
        echo "Destroying ", self::class, "\n";
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(1.0, $suspension->resume(...));
        $suspension->suspend();
        echo "Finished " . self::class, "\n";
    }
}

EventLoop::defer(function () {
    echo "start\n";
});

a::getInstance();

EventLoop::run();

?>
--EXPECT--
start
Destroying a
Finished a
