<?php

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

$ticks = (int) ($argv[1] ?? 1000 * 100);
$tick = function () use (&$tick, &$ticks) {
    if ($ticks > 0) {
        --$ticks;
        EventLoop::defer($tick);
    } else {
        echo 'done';
    }
};

$tick();

EventLoop::run();
