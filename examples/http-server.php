<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

// start TCP/IP server on localhost:8080
// for illustration purposes only, should use socket abstracting instead
$server = \stream_socket_server('tcp://0.0.0.0:8080');
if (!$server) {
    exit(1);
}
\stream_set_blocking($server, false);

echo "Visit http://localhost:8080/ in your browser." . PHP_EOL;

// wait for incoming connections on server socket
EventLoop::onReadable($server, function ($watcher, $server) {
    $conn = \stream_socket_accept($server);
    $data = "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 3\r\n\r\nHi\n";
    EventLoop::onWritable($conn, function ($watcher, $conn) use (&$data) {
        $written = \fwrite($conn, $data);
        if ($written === \strlen($data)) {
            \fclose($conn);
            EventLoop::cancel($watcher);
        } else {
            $data = \substr($data, $written);
        }
    });
});

EventLoop::repeat(5, function () {
    $memory = \memory_get_usage() / 1024;
    $formatted = \number_format($memory).' KiB';
    echo "Current memory usage: {$formatted}\n";
});

EventLoop::run();
