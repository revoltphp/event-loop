<?php

declare(strict_types=1);

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';

// data can be given as first argument or defaults to "y"
$data = ($argv[1] ?? 'y') . "\n";

// repeat data X times in order to fill around 200 KB
$data = \str_repeat($data, (int) \round(200 / \strlen($data)));

if (!\defined('STDOUT') || \stream_set_blocking(STDOUT, false) !== true) {
    \fwrite(STDERR, 'ERROR: Unable to set STDOUT non-blocking (not CLI or Windows?)' . PHP_EOL);
    exit(1);
}

// write data to STDOUT whenever its write buffer accepts data
// for illustrations purpose only, should use a proper stream abstraction instead
EventLoop::onWritable(STDOUT, function ($watcher, $stdout) use (&$data) {
    // try to write data
    $r = \fwrite($stdout, $data);

    // nothing could be written despite being writable => closed
    if ($r === 0) {
        EventLoop::cancel($watcher);
        \stream_set_blocking($stdout, true);
        \fclose($stdout);
        \fwrite(STDERR, 'Stopped because STDOUT closed' . PHP_EOL);

        return;
    }

    // implement a very simple ring buffer, unless everything has been written at once:
    // everything written in this iteration will be appended for next iteration
    if (isset($data[$r])) {
        $data = \substr($data, $r) . \substr($data, 0, $r);
    }
});

EventLoop::run();
