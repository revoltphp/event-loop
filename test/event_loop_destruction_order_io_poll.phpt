--TEST--
Issue #105: Ensure the callback fiber is always alive as long as the event loop lives (io_poll driver)
--SKIPIF--
<?php

if (PHP_VERSION_ID < 80400) {
    echo 'skip PHP 8.4+ required';
    return;
}

require 'vendor/autoload.php';

if (!class_exists(Io\Poll\Context::class) || !class_exists(Time\Duration::class)) {
    echo 'skip Io\Poll API required';
}

?>
--FILE--
<?php

use Revolt\EventLoop;
use Revolt\EventLoop\Driver\IoPollDriver;

require 'vendor/autoload.php';

EventLoop::setDriver(new IoPollDriver());

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
