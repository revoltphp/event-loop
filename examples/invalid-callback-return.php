<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;

print "Press Ctrl+C to exit..." . PHP_EOL;

$suspension = EventLoop::getSuspension();

EventLoop::onSignal(\SIGINT, function (string $watcherId) use ($suspension) {
    EventLoop::cancel($watcherId);

    print "Caught SIGINT, exiting..." . PHP_EOL;

    $suspension->resume();

    return new \stdClass();
});

$suspension->suspend();
