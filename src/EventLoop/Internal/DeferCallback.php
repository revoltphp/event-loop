<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\CallbackType;

/** @internal */
final class DeferCallback extends DriverCallback
{
    #[\Override]
    public function getType(): CallbackType
    {
        return CallbackType::Defer;
    }
}
