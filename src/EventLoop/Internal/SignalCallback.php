<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\CallbackType;

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

    #[\Override]
    public function getType(): CallbackType
    {
        return CallbackType::Signal;
    }
}
