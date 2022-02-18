<?php

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Continuation;
use Revolt\EventLoop\Suspension;

/**
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class DriverSuspension implements Suspension
{
    public function __construct(
        private \Closure $run,
        private \Closure $queue,
        private \Closure $interrupt,
        private SuspensionState $state
    ) {
    }

    public function getContinuation(): Continuation
    {
        if ($this->state->reference && $this->state->reference->get() === null) {
            throw new \Error('Suspension already destroyed; create a Continuation before suspending');
        }

        return new DriverContinuation($this->queue, $this->interrupt, $this->state);
    }

    public function suspend(): mixed
    {
        if ($this->state->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again');
        }

        if ($this->state->reference?->get() !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber');
        }

        $this->state->pending = true;

        // Awaiting from within a fiber.
        if ($this->state->reference) {

            try {
                return \Fiber::suspend();
            } catch (\FiberError $exception) {
                $this->state->pending = false;
                $this->state->fiberError = $exception;

                throw $exception;
            }
        }

        // Awaiting from {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->state->pending) {
            $this->state->pending = false;
            $result && $result(); // Unwrap any uncaught exceptions from the event loop

            $info = '';
            $states = $this->state->suspensions->get();
            if ($states) {
                \gc_collect_cycles();

                /** @var SuspensionState $state */
                foreach ($states as $state) {
                    if ($state->reference?->get() === null) {
                        continue;
                    }

                    $reflectionFiber = new \ReflectionFiber($state->fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }

            throw new \Error('Event loop terminated without resuming the current continuation:' . $info);
        }

        return $result();
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
