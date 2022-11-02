<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/** @internal */
final class TimerCallback extends DriverCallback
{
    public function __construct(
        string $id,
        public readonly float $interval,
        \Closure $callback,
        public float $expiration,
        public readonly bool $repeat = false
    ) {
        parent::__construct($id, $callback);
    }
}
