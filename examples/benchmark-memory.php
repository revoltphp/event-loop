<?php

declare(strict_types=1);

/**
 * Run the script indefinitely seconds with the loop from the factory and report every 2 seconds:
 * php 95-benchmark-memory.php
 * Run the script for 30 seconds with the stream_select loop and report every 10 seconds:
 * php 95-benchmark-memory.php -t 30 -l StreamSelect -r 10.
 */

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;

require __DIR__ . '/../vendor/autoload.php';

$args = \getopt('t:l:r:');
/** @psalm-suppress RiskyCast */
$t  = (int) \round((\array_key_exists('t', $args) ? (int) $args['t'] : 0));
if (\array_key_exists('d', $args)) {
    if (\is_string($args['d'])) {
        $loopClass = 'Revolt\EventLoop\Driver\\' . $args['d'] . 'Driver';
        if (\class_exists($loopClass)) {
            $loop = new ($loopClass)();
            if ($loop instanceof Driver) {
                EventLoop::setDriver($loop);
            }
        }
    }
}

/** @psalm-suppress RiskyCast */
$r = (int) \round((\array_key_exists('r', $args) ? (int) $args['r'] : 2));

$runs = 0;

if (5 < $t) {
    EventLoop::delay($t, function () {
        EventLoop::getDriver()->stop();
    });
}

EventLoop::repeat(0.001, function () use (&$runs) {
    $runs++;

    EventLoop::repeat(1, function (string $watcher) {
        EventLoop::cancel($watcher);
    });
});

EventLoop::repeat($r, function () use (&$runs) {
    $kmem = \round(\memory_get_usage() / 1024);
    $kmemReal = \round(\memory_get_usage(true) / 1024);
    echo "Runs:\t\t\t$runs\n";
    echo "Memory (internal):\t$kmem KiB\n";
    echo "Memory (real):\t\t$kmemReal KiB\n";
    echo \str_repeat('-', 50), "\n";
});

echo "PHP Version:\t\t", PHP_VERSION, "\n";
echo "Loop\t\t\t", \get_class(EventLoop::getDriver()), "\n";
echo "Time\t\t\t", \date('r'), "\n";

echo \str_repeat('-', 50), "\n";

$beginTime = \time();
EventLoop::run();
$endTime = \time();
$timeTaken = $endTime - $beginTime;

echo "PHP Version:\t\t", PHP_VERSION, "\n";
echo "Loop\t\t\t", \get_class(EventLoop::getDriver()), "\n";
echo "Time\t\t\t", \date('r'), "\n";
echo "Time taken\t\t", $timeTaken, " seconds\n";
if ($timeTaken > 0) {
    echo "Runs per second\t\t", \round($runs / $timeTaken), "\n";
}
