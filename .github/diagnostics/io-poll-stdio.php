<?php

// Temporary: shows how Io\Poll treats the standard streams on this runner.

use Io\Poll\{Backend, Context, Event};
use Time\Duration;

echo 'PHP ', PHP_VERSION, ', backends: ', implode(', ', array_map(fn (Backend $b) => $b->name, Backend::getAvailableBackends())), "\n";

foreach (['STDIN' => STDIN, 'STDOUT' => STDOUT, 'STDERR' => STDERR] as $name => $stream) {
    $meta = stream_get_meta_data($stream);
    printf("%s: seekable=%s type=%s\n", $name, var_export($meta['seekable'], true), $meta['stream_type']);

    foreach ([Backend::Auto, Backend::Poll] as $backend) {
        try {
            $context = new Context($backend);
            $watcher = $context->add(new StreamPollHandle($stream), [Event::Read, Event::Write]);
            $start = hrtime(true);
            $ready = $context->wait(Duration::fromSeconds(0, 100_000_000));
            printf("  %s (%s): added, wait() took %.3fs, triggered: %s\n", $backend->name, $context->getBackend()->name, (hrtime(true) - $start) / 1e9,
                implode(' ', array_map(fn ($w) => implode(',', array_map(fn ($e) => $e->name, $w->getTriggeredEvents())), $ready)) ?: 'nothing');
            $watcher->remove();
        } catch (Throwable $e) {
            printf("  %s: %s code=%d: %s\n", $backend->name, get_class($e), $e->getCode(), $e->getMessage());
        }
    }
}

$context = new Context();
$start = hrtime(true);
try {
    $context->wait(Duration::fromSeconds(0));
    printf("empty context, wait(0) took %.3fs\n", (hrtime(true) - $start) / 1e9);
} catch (Throwable $e) {
    printf("empty context, wait(0): %s code=%d: %s\n", get_class($e), $e->getCode(), $e->getMessage());
}
