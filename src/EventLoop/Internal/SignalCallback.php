<?php

namespace Revolt\EventLoop\Internal;

/** @internal */
final class SignalCallback extends Callback
{
    public function __construct(
        string $id,
        callable $callback,
        public int $signal
    ) {
        parent::__construct($id, $callback);
    }
}
