<?php

namespace Revolt\EventLoop\Internal;

/** @internal */
abstract class StreamWatcher extends Watcher
{
    /**
     * @param resource|object $stream
     */
    public function __construct(
        string $id,
        callable $callback,
        public mixed $stream
    ) {
        parent::__construct($id, $callback);
    }
}
