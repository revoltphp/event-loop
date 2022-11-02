<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

if (!\defined('STDIN') || \stream_set_blocking(STDIN, false) !== true) {
    \fwrite(STDERR, 'ERROR: Unable to set STDIN non-blocking (not CLI or Windows?)' . PHP_EOL);
    exit(1);
}


// read everything from STDIN and report number of bytes
// for illustration purposes only, should use a package of
// choice abstracting streams instead, that handles edge cases well
EventLoop::onReadable(STDIN, static function (string $watcher, $stream): void {
    $chunk = \trim(\fread($stream, 64 * 1024));

    // reading nothing means we reached EOF
    if ($chunk === '') {
        EventLoop::cancel($watcher);
        \stream_set_blocking($stream, true);
        \fclose($stream);
        return;
    }

    echo \strlen($chunk) . ' bytes' . PHP_EOL;
});

EventLoop::run();
