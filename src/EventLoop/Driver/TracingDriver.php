<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;

final class TracingDriver implements Driver
{
    private readonly Driver $driver;

    /** @var array<string, true> */
    private array $enabledCallbacks = [];

    /** @var array<string, true> */
    private array $unreferencedCallbacks = [];

    /** @var array<string, string> */
    private array $creationTraces = [];

    /** @var array<string, string> */
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

    public function getSuspension(): Suspension
    {
        return $this->driver->getSuspension();
    }

    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    public function defer(\Closure $closure): string
    {
        $id = $this->driver->defer(function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function delay(float $delay, \Closure $closure): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function repeat(float $interval, \Closure $closure): string
    {
        $id = $this->driver->repeat($interval, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->onReadable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function onWritable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->onWritable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function onSignal(int $signal, \Closure $closure): string
    {
        $id = $this->driver->onSignal($signal, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function enable(string $callbackId): string
    {
        try {
            $this->driver->enable($callbackId);
            $this->enabledCallbacks[$callbackId] = true;
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    public function cancel(string $callbackId): void
    {
        $this->driver->cancel($callbackId);

        if (!isset($this->cancelTraces[$callbackId])) {
            $this->cancelTraces[$callbackId] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        unset($this->enabledCallbacks[$callbackId], $this->unreferencedCallbacks[$callbackId]);
    }

    public function disable(string $callbackId): string
    {
        $this->driver->disable($callbackId);
        unset($this->enabledCallbacks[$callbackId]);

        return $callbackId;
    }

    public function reference(string $callbackId): string
    {
        try {
            $this->driver->reference($callbackId);
            unset($this->unreferencedCallbacks[$callbackId]);
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    public function unreference(string $callbackId): string
    {
        $this->driver->unreference($callbackId);
        $this->unreferencedCallbacks[$callbackId] = true;

        return $callbackId;
    }

    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    public function getErrorHandler(): ?\Closure
    {
        return $this->driver->getErrorHandler();
    }

    /** @inheritdoc */
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    public function dump(): string
    {
        $dump = "Enabled, referenced callbacks keeping the loop running: ";

        foreach ($this->enabledCallbacks as $callbackId => $_) {
            if (isset($this->unreferencedCallbacks[$callbackId])) {
                continue;
            }

            $dump .= "Callback identifier: " . $callbackId . "\r\n";
            $dump .= $this->getCreationTrace($callbackId);
            $dump .= "\r\n\r\n";
        }

        return \rtrim($dump);
    }

    public function getIdentifiers(): array
    {
        return $this->driver->getIdentifiers();
    }

    public function getType(string $callbackId): CallbackType
    {
        return $this->driver->getType($callbackId);
    }

    public function isEnabled(string $callbackId): bool
    {
        return $this->driver->isEnabled($callbackId);
    }

    public function isReferenced(string $callbackId): bool
    {
        return $this->driver->isReferenced($callbackId);
    }

    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }

    private function getCreationTrace(string $callbackId): string
    {
        return $this->creationTraces[$callbackId] ?? 'No creation trace, yet.';
    }

    private function getCancelTrace(string $callbackId): string
    {
        return $this->cancelTraces[$callbackId] ?? 'No cancellation trace, yet.';
    }

    /**
     * Formats a stacktrace obtained via `debug_backtrace()`.
     *
     * @param list<array{
     *     args?: list<mixed>,
     *     class?: class-string,
     *     file?: string,
     *     function: string,
     *     line?: int,
     *     object?: object,
     *     type?: string
     * }> $trace Output of `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"], $e['line'])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
