<?php

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidWatcherError;

final class TracingDriver implements Driver
{
    private Driver $driver;

    /** @var true[] */
    private array $enabledWatchers = [];

    /** @var true[] */
    private array $unreferencedWatchers = [];

    /** @var string[] */
    private array $creationTraces = [];

    /** @var string[] */
    private array $cancelTraces = [];

    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    public function run(): void
    {
        $this->driver->run();
    }

    public function stop(): void
    {
        $this->driver->stop();
    }

    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    public function defer(callable $callback): string
    {
        $id = $this->driver->defer(function (...$args) use ($callback) {
            $this->cancel($args[0]);
            return $callback(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledWatchers[$id] = true;

        return $id;
    }

    public function delay(float $delay, callable $callback): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($callback) {
            $this->cancel($args[0]);
            return $callback(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledWatchers[$id] = true;

        return $id;
    }

    public function repeat(float $interval, callable $callback): string
    {
        $id = $this->driver->repeat($interval, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledWatchers[$id] = true;

        return $id;
    }

    public function onReadable(mixed $stream, callable $callback): string
    {
        $id = $this->driver->onReadable($stream, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledWatchers[$id] = true;

        return $id;
    }

    public function onWritable(mixed $stream, callable $callback): string
    {
        $id = $this->driver->onWritable($stream, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledWatchers[$id] = true;

        return $id;
    }

    public function onSignal(int $signo, callable $callback): string
    {
        $id = $this->driver->onSignal($signo, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledWatchers[$id] = true;

        return $id;
    }

    public function enable(string $watcherId): string
    {
        try {
            $this->driver->enable($watcherId);
            $this->enabledWatchers[$watcherId] = true;
        } catch (InvalidWatcherError $e) {
            throw new InvalidWatcherError(
                $watcherId,
                $e->getMessage() . "\r\n\r\n" . $this->getTraces($watcherId)
            );
        }

        return $watcherId;
    }

    public function cancel(string $watcherId): void
    {
        $this->driver->cancel($watcherId);

        if (!isset($this->cancelTraces[$watcherId])) {
            $this->cancelTraces[$watcherId] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        unset($this->enabledWatchers[$watcherId], $this->unreferencedWatchers[$watcherId]);
    }

    public function disable(string $watcherId): string
    {
        $this->driver->disable($watcherId);
        unset($this->enabledWatchers[$watcherId]);

        return $watcherId;
    }

    public function reference(string $watcherId): string
    {
        try {
            $this->driver->reference($watcherId);
            unset($this->unreferencedWatchers[$watcherId]);
        } catch (InvalidWatcherError $e) {
            throw new InvalidWatcherError(
                $watcherId,
                $e->getMessage() . "\r\n\r\n" . $this->getTraces($watcherId)
            );
        }

        return $watcherId;
    }

    public function unreference(string $watcherId): string
    {
        $this->driver->unreference($watcherId);
        $this->unreferencedWatchers[$watcherId] = true;

        return $watcherId;
    }

    public function setErrorHandler(callable $callback = null): ?callable
    {
        return $this->driver->setErrorHandler($callback);
    }

    /** @inheritdoc */
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    public function dump(): string
    {
        $dump = "Enabled, referenced watchers keeping the loop running: ";

        foreach ($this->enabledWatchers as $watcher => $_) {
            if (isset($this->unreferencedWatchers[$watcher])) {
                continue;
            }

            $dump .= "Watcher ID: " . $watcher . "\r\n";
            $dump .= $this->getCreationTrace($watcher);
            $dump .= "\r\n\r\n";
        }

        return \rtrim($dump);
    }

    public function getInfo(): array
    {
        return $this->driver->getInfo();
    }

    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    public function queue(callable $callback, mixed ...$args): void
    {
        $this->driver->queue($callback, ...$args);
    }

    private function getTraces(string $watcherId): string
    {
        return "Creation Trace:\r\n" . $this->getCreationTrace($watcherId) . "\r\n\r\n" .
            "Cancellation Trace:\r\n" . $this->getCancelTrace($watcherId);
    }

    private function getCreationTrace(string $watcher): string
    {
        return $this->creationTraces[$watcher] ?? 'No creation trace, yet.';
    }

    private function getCancelTrace(string $watcher): string
    {
        return $this->cancelTraces[$watcher] ?? 'No cancellation trace, yet.';
    }

    /**
     * Formats a stacktrace obtained via `debug_backtrace()`.
     *
     * @param array<array{file?: string, line: int, type?: string, class?: class-string, function: string}> $trace Output of
     *     `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
