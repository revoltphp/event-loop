<?php

namespace Revolt\EventLoop\Internal;

class SuspensionState
{
    public bool $pending = false;

    public ?\Fiber $fiber = null;

    public ?\WeakReference $reference;

    public \FiberError $fiberError;

    private int $refCount = 0;

    public function __construct(
        ?\Fiber $fiber,
        public \WeakReference $suspensions,
    ) {
        $this->reference = $fiber ? \WeakReference::create($fiber) : null;
    }

    public function addReference(): void
    {
        if (!$this->refCount++) {
            $this->fiber = $this->reference?->get();
        }
    }

    public function deleteReference(): void
    {
        \assert($this->refCount > 0);

        if (!--$this->refCount) {
            $this->fiber = null;
        }
    }
}
