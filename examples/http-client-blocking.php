<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

// connect to www.google.com:80 (blocking call!)
// for illustration purposes only, should use a proper socket abstraction instead
$stream = \stream_socket_client('tcp://www.google.com:80');
if (!$stream) {
    exit(1);
}
\stream_set_blocking($stream, false);

// send HTTP request
\fwrite($stream, "GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");

// wait for HTTP response
EventLoop::onReadable($stream, function ($watcher, $stream) {
    $chunk = \fread($stream, 64 * 1024);

    // reading nothing means we reached EOF
    if ($chunk === '') {
        echo '[END]' . PHP_EOL;
        EventLoop::cancel($watcher);
        \fclose($stream);
        return;
    }

    echo "Read " . \strlen($chunk) . " bytes..." . PHP_EOL;
});

EventLoop::run();
