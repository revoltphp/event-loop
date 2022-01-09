<?php

namespace Revolt\EventLoop\Internal;

/** @internal */
final class TimerCallback extends DriverCallback
{
    public function __construct(
        string $id,
        public float $interval,
        \Closure $callback,
        public float $expiration,
        public bool $repeat = false
    ) {
        parent::__construct($id, $callback);
    }
}
