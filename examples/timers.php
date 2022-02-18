#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;

$suspension = EventLoop::getSuspension();
$continuation = $suspension->getContinuation();

$repeatWatcher = EventLoop::repeat(1, function () {
    print "++ Executing watcher created by Loop::repeat()" . PHP_EOL;
});

EventLoop::delay(5, function () use ($continuation, $repeatWatcher) {
    print "++ Executing watcher created by Loop::delay()" . PHP_EOL;

    EventLoop::cancel($repeatWatcher);
    $continuation->resume();

    print "++ Executed after script ended" . PHP_EOL;
});

$suspension->suspend();

print '++ Script end' . PHP_EOL;
