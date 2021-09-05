<?php

namespace Revolt\EventLoop\Internal;

/** @internal */
final class TimerWatcher extends Watcher
{
    public function __construct(
        string $id,
        public float $interval,
        callable $callback,
        public float $expiration,
        public bool $repeat = false
    ) {
        parent::__construct($id, $callback);
    }
}
