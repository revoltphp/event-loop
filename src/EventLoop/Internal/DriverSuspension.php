<?php

/** @noinspection PhpPropertyOnlyWrittenInspection */

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Suspension;

/**
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class DriverSuspension implements Suspension
{
    private ?\Fiber $suspendedFiber = null;

    /** @var \WeakReference<\Fiber>|null */
    private readonly ?\WeakReference $fiberRef;

    private ?\Error $error = null;

    private bool $pending = false;

    private bool $deadMain = false;

    /**
     * @param \WeakMap<object, \WeakReference<DriverSuspension>> $suspensions
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $queue,
        private readonly \Closure $interrupt,
        private readonly \WeakMap $suspensions,
    ) {
        $fiber = \Fiber::getCurrent();

        $this->fiberRef = $fiber ? \WeakReference::create($fiber) : null;
    }

    public function resume(mixed $value = null): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }

        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $value): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->resume($value);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => $value);
        }
    }

    public function suspend(): mixed
    {
        // Throw exception when trying to use old dead {main} suspension
        if ($this->deadMain) {
            throw new \Error(
                'Suspension cannot be suspended after an uncaught exception is thrown from the event loop',
            );
        }

        if ($this->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again');
        }

        $fiber = $this->fiberRef?->get();

        if ($fiber !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber');
        }

        $this->pending = true;
        $this->error = null;

        // Awaiting from within a fiber.
        if ($fiber) {
            $this->suspendedFiber = $fiber;

            try {
                $value = \Fiber::suspend();
                $this->suspendedFiber = null;
            } catch (\FiberError $error) {
                $this->pending = false;
                $this->suspendedFiber = null;
                $this->error = $error;

                throw $error;
            }

            // Setting $this->suspendedFiber = null in finally will set the fiber to null if a fiber is destroyed
            // as part of a cycle collection, causing an error if the suspension is subsequently resumed.

            return $value;
        }

        // Awaiting from {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            // This is now a dead {main} suspension.
            $this->deadMain = true;

            // Unset suspension for {main} using queue closure.
            unset($this->suspensions[$this->queue]);

            $result && $result(); // Unwrap any uncaught exceptions from the event loop

            \gc_collect_cycles(); // Collect any circular references before dumping pending suspensions.

            $info = '';
            foreach ($this->suspensions as $suspensionRef) {
                if ($suspension = $suspensionRef->get()) {
                    \assert($suspension instanceof self);
                    $fiber = $suspension->fiberRef?->get();
                    if ($fiber === null) {
                        continue;
                    }

                    $reflectionFiber = new \ReflectionFiber($fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }

            throw new \Error('Event loop terminated without resuming the current suspension (the cause is either a fiber deadlock, or an incorrectly unreferenced/canceled watcher):' . $info);
        }

        return $result();
    }

    public function throw(\Throwable $throwable): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }

        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $throwable): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->throw($throwable);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => throw $throwable);
        }
    }

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
