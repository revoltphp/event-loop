<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

$n = isset($argv[1]) ? (int) $argv[1] : 1000 * 100;

for ($i = 0; $i < $n; ++$i) {
    EventLoop::defer(function () { });
}

EventLoop::run();
