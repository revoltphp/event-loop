<?php

namespace Revolt\EventLoop;

final class UncaughtThrowable extends \Error
{
    public static function throwingCallback(\Throwable $previous) {
        return new self("Uncaught %s thrown in event loop callback; use Revolt\EventLoop::setErrorHandler() to gracefully handle such exceptions, e.g. logging them%s", $previous);
    }

    public static function throwingErrorHandler(\Throwable $previous) {
        return new self("Uncaught %s thrown from event loop error handler; %s", $previous);
    }

    private function __construct(string $message, \Throwable $previous)
    {
        parent::__construct(\sprintf(
            $message,
            \str_replace("\0", '@', \get_class($previous)), // replace NUL-byte in anonymous class name
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }
}
