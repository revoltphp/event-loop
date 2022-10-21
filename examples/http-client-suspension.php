<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

function fetch(string $url): string
{
    $suspension = EventLoop::getSuspension();

    $parsedUrl = \parse_url($url);
    if (!isset($parsedUrl['host'], $parsedUrl['path'])) {
        throw new \Exception('Failed to parse URL: ' . $url);
    }

    // resolve hostname before establishing TCP/IP connection (resolving DNS is still blocking here)
    // for illustration purposes only, should use a proper DNS abstraction instead!
    $ip = \gethostbyname($parsedUrl['host']);
    if (\ip2long($ip) === false) {
        echo 'Unable to resolve hostname' . PHP_EOL;
        exit(1);
    }

    // establish TCP/IP connection (non-blocking)
    // for illustraction purposes only, should use a proper socket abstraction instead!
    $stream = \stream_socket_client('tcp://' . $ip . ':80', $errno, $errstr, PHP_INT_MAX, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
    if (!$stream) {
        exit(1);
    }
    \stream_set_blocking($stream, false);

    // wait for connection success/error
    $watcher = EventLoop::onWritable($stream, fn () => $suspension->resume());
    $suspension->suspend();
    EventLoop::cancel($watcher);

    // send HTTP request
    \fwrite($stream, "GET " . $parsedUrl['path'] . " HTTP/1.1\r\nHost: " . $parsedUrl['host'] . "\r\nConnection: close\r\n\r\n");

    $buffer = '';

    // wait for HTTP response
    $watcher = EventLoop::onReadable($stream, fn () => $suspension->resume());

    do {
        $suspension->suspend();
        $chunk = \fread($stream, 64 * 1024);
        $buffer .= $chunk;
    } while ($chunk !== '');

    EventLoop::cancel($watcher);
    \fclose($stream);

    return $buffer;
}

function extractHeader(string $httpResponse): string
{
    return \explode("\r\n\r\n", $httpResponse, 2)[0];
}

echo extractHeader(fetch('http://www.google.com/'));
