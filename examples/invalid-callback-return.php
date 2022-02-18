#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;

print "Press Ctrl+C to exit..." . PHP_EOL;

$suspension = EventLoop::getSuspension();
$continuation = $suspension->getContinuation();

EventLoop::onSignal(\SIGINT, function (string $watcherId) use ($continuation) {
    EventLoop::cancel($watcherId);

    print "Caught SIGINT, exiting..." . PHP_EOL;

    $continuation->resume();

    return new \stdClass();
});

$suspension->suspend();
