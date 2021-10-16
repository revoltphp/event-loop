<?php

namespace Revolt\EventLoop;

interface Listener
{
    /**
     * Called when a Suspension is suspended.
     *
     * @param int $id The object ID of the Suspension.
     */
    public function onSuspend(int $id): void;

    /**
     * Called when a Suspension is resumed.
     *
     * @param int $id The object ID of the Suspension.
     */
    public function onResume(int $id): void;
}
