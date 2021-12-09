<?php

namespace Revolt\EventLoop;

final class UnhandledException extends \Error
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct(\sprintf(
            "Unhandled %s thrown in event loop%s",
            \str_replace("\0", '@', \get_class($previous)), // replace NUL-byte in anonymous class name
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }
}
