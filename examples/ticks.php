<?php

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
