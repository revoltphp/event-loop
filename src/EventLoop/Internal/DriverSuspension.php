<?php

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

    private readonly \WeakReference $suspensions;

    /**
     * @param \Closure $run
     * @param \Closure $queue
     * @param \Closure $interrupt
     *
     * @internal
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $queue,
        private readonly \Closure $interrupt,
        \WeakMap $suspensions
    ) {
        $fiber = \Fiber::getCurrent();

        $this->fiberRef = $fiber ? \WeakReference::create($fiber) : null;
        $this->suspensions = \WeakReference::create($suspensions);
    }

    public function resume(mixed $value = null): void
    {
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)($fiber->resume(...), $value);
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => $value);
        }
    }

    public function suspend(): mixed
    {
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
                return \Fiber::suspend();
            } catch (\FiberError $error) {
                $this->pending = false;
                $this->error = $error;

                throw $error;
            } finally {
                $this->suspendedFiber = null;
            }
        }

        // Awaiting from {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            $this->pending = false;

            try {
                $result && $result(); // Unwrap any uncaught exceptions from the event loop
            } catch (\Throwable $throwable) {
                $this->error = new \Error(
                    'Suspension cannot be resumed after an uncaught exception is thrown from the event loop',
                );

                throw $throwable;
            }

            $info = '';
            $suspensions = $this->suspensions->get();
            if ($suspensions) {
                \gc_collect_cycles();

                /** @var self $suspension */
                foreach ($suspensions as $suspension) {
                    $fiber = $suspension->fiberRef?->get();
                    if ($fiber === null) {
                        continue;
                    }

                    $reflectionFiber = new \ReflectionFiber($fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }

            throw $this->error = new \Error('Event loop terminated without resuming the current suspension:' . $info);
        }

        return $result();
    }

    public function throw(\Throwable $throwable): void
    {
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)($fiber->throw(...), $throwable);
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
