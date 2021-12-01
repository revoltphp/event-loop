<?php

namespace Revolt\EventLoop\Internal;

/** @internal */
final class SignalCallback extends Callback
{
    public function __construct(
        string $id,
        \Closure $closure,
        public int $signal
    ) {
        parent::__construct($id, $closure);
    }
}
