#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;

if (\stream_set_blocking(STDIN, false) !== true) {
    \fwrite(STDERR, "Unable to set STDIN to non-blocking" . PHP_EOL);
    exit(1);
}

print "Write something and hit enter" . PHP_EOL;

$suspension = EventLoop::createSuspension();

$readWatcher = EventLoop::onReadable(STDIN, function ($watcherId, $stream) use ($suspension) {
    EventLoop::cancel($watcherId);

    $chunk = \fread($stream, 8192);

    print "Read " . \strlen($chunk) . " bytes" . PHP_EOL;

    $suspension->resume(null);
});

$timeoutWatcher = EventLoop::delay(5000, fn () => $suspension->resume(null));

$suspension->suspend();

EventLoop::cancel($readWatcher);
EventLoop::cancel($timeoutWatcher);
