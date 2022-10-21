<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

// resolve hostname before establishing TCP/IP connection (resolving DNS is still blocking here)
// for illustration purposes only, should use a proper DNS abstraction instead!
$ip = \gethostbyname('www.google.com');
if (\ip2long($ip) === false) {
    echo 'Unable to resolve hostname' . PHP_EOL;
    exit(1);
}

// establish TCP/IP connection (non-blocking)
// for illustration purposes only, should use a proper socket abstraction instead!
$stream = \stream_socket_client('tcp://' . $ip . ':80', $errno, $errstr, PHP_INT_MAX, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
if (!$stream) {
    exit(1);
}
\stream_set_blocking($stream, false);

// print progress every 10ms
echo 'Connecting';
$timer = EventLoop::repeat(0.01, function () {
    echo '.';
});

// wait for connection success/error
EventLoop::onWritable($stream, function ($watcher, $stream) use ($timer) {
    EventLoop::cancel($watcher);
    EventLoop::cancel($timer);

    echo '[connected]' . PHP_EOL;

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
});

EventLoop::run();
