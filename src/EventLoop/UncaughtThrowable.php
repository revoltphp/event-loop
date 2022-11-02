<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

use Revolt\EventLoop\Internal\ClosureHelper;

final class UncaughtThrowable extends \Error
{
    public static function throwingCallback(\Closure $closure, \Throwable $previous): self
    {
        return new self(
            "Uncaught %s thrown in event loop callback %s; use Revolt\EventLoop::setErrorHandler() to gracefully handle such exceptions%s",
            $closure,
            $previous
        );
    }

    public static function throwingErrorHandler(\Closure $closure, \Throwable $previous): self
    {
        return new self("Uncaught %s thrown in event loop error handler %s%s", $closure, $previous);
    }

    private function __construct(string $message, \Closure $closure, \Throwable $previous)
    {
        parent::__construct(\sprintf(
            $message,
            \str_replace("\0", '@', \get_class($previous)), // replace NUL-byte in anonymous class name
            ClosureHelper::getDescription($closure),
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }
}
