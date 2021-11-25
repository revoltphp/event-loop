<?php

namespace Revolt\EventLoop\Internal;

/** @internal */
abstract class StreamCallback extends Callback
{
    /**
     * @param resource|object $stream
     */
    public function __construct(
        string $id,
        \Closure $closure,
        public mixed $stream
    ) {
        parent::__construct($id, $closure);
    }
}
