<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

enum CallbackType
{
    case Defer;
    case Delay;
    case Repeat;
    case Readable;
    case Writable;
    case Signal;
}
