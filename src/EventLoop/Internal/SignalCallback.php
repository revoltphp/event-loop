<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/** @internal */
final class SignalCallback extends DriverCallback
{
    public function __construct(
        string $id,
        \Closure $closure,
        public readonly int $signal
    ) {
        parent::__construct($id, $closure);
    }
}
