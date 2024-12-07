<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;

\register_shutdown_function(function (): void {
    \register_shutdown_function(function (): void {
        EventLoop::defer(function (): void {
            print 'Shutdown function registered within pre-loop-run shutdown function' . PHP_EOL;
        });
    });


    EventLoop::defer(function (): void {
        print 'Shutdown function registered before EventLoop::run()' . PHP_EOL;
    });
});

EventLoop::run();

\register_shutdown_function(function (): void {
    \register_shutdown_function(function (): void {
        EventLoop::defer(function (): void {
            print 'Shutdown function registered within post-loop-run shutdown function' . PHP_EOL;
        });
    });

    EventLoop::defer(function (): void {
        print 'Shutdown function registered after EventLoop::run()' . PHP_EOL;
    });
});

print 'End of script' . PHP_EOL;
