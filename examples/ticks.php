<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

EventLoop::defer(static function () {
    echo 'b';
});
EventLoop::defer(static function () {
    echo 'c';
});
echo 'a';

EventLoop::run();
