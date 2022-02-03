<?php

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
    private ?\Fiber $fiber;

    private ?\FiberError $fiberError = null;

    private \Closure $run;

    private \Closure $queue;

    private \Closure $interrupt;

    private bool $pending = false;

    private \WeakReference $suspensions;

    /**
     * @param \Closure $run
     * @param \Closure $queue
     * @param \Closure $interrupt
     *
     * @internal
     */
    public function __construct(\Closure $run, \Closure $queue, \Closure $interrupt, \WeakMap $suspensions)
    {
        $this->run = $run;
        $this->queue = $queue;
        $this->interrupt = $interrupt;
        $this->fiber = \Fiber::getCurrent();
        $this->suspensions = \WeakReference::create($suspensions);
    }

    public function resume(mixed $value = null): void
    {
        if (!$this->pending) {
            throw $this->fiberError ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->pending = false;

        if ($this->fiber) {
            ($this->queue)(\Closure::fromCallable([$this->fiber, 'resume']), $value);
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

        if ($this->fiber !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber');
        }

        $this->pending = true;

        // Awaiting from within a fiber.
        if ($this->fiber) {
            try {
                return \Fiber::suspend();
            } catch (\FiberError $exception) {
                $this->pending = false;
                $this->fiberError = $exception;

                throw $exception;
            }
        }

        // Awaiting from {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            $this->pending = false;
            $result && $result(); // Unwrap any uncaught exceptions from the event loop

            $info = '';
            $suspensions = $this->suspensions->get();
            if ($suspensions) {
                \gc_collect_cycles();

                foreach ($suspensions as $suspension) {
                    if ($suspension->fiber === null) {
                        continue;
                    }

                    $reflectionFiber = new \ReflectionFiber($suspension->fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }

            throw new \Error('Event loop terminated without resuming the current suspension:' . $info);
        }

        return $result();
    }

    public function throw(\Throwable $throwable): void
    {
        if (!$this->pending) {
            throw $this->fiberError ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->pending = false;

        if ($this->fiber) {
            ($this->queue)(\Closure::fromCallable([$this->fiber, 'throw']), $throwable);
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
