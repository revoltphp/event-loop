<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\CallbackType;

/** @internal */
final class StreamReadableCallback extends StreamCallback
{
    /**
     * @psalm-pure
     */
    #[\Override]
    public function getType(): CallbackType
    {
        return CallbackType::Readable;
    }
}
