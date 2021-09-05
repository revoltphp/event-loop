<?php

final class ReflectionFiber
{
    /**
     * @param Fiber $fiber Any Fiber object, including those that are not started or have
     *                     terminated.
     */
    public function __construct(Fiber $fiber)
    {
    }

    /**
     * @return Fiber The reflected Fiber object.
     */
    public function getFiber(): Fiber
    {
    }

    /**
     * @return string Current file of fiber execution.
     *
     * @throws Error If the fiber has not been started or has terminated.
     */
    public function getExecutingFile(): string
    {
    }

    /**
     * @return int Current line of fiber execution.
     *
     * @throws Error If the fiber has not been started or has terminated.
     */
    public function getExecutingLine(): int
    {
    }

    /**
     * @param int $options Same flags as {@see debug_backtrace()}.
     *
     * @return array Fiber backtrace, similar to {@see debug_backtrace()}
     *               and {@see ReflectionGenerator::getTrace()}.
     *
     * @throws Error If the fiber has not been started or has terminated.
     */
    public function getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT): array
    {
    }

    /**
     * @return callable Callable used to create the fiber.
     *
     * @throws Error If the fiber has been terminated.
     */
    public function getCallable(): callable
    {
    }
}
