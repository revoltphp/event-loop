<?php

namespace Revolt\EventLoop;

/**
 * MUST be thrown if any operation (except disable() and cancel()) is attempted with an invalid callback identifier.
 *
 * An invalid callback identifier is any identifier that is not yet emitted by the driver or cancelled by the user.
 */
final class InvalidWatcherError extends \Error
{
    /** @var string */
    private string $callbackId;

    /**
     * @param string $callbackId The callback identifier.
     * @param string $message The exception message.
     */
    public function __construct(string $callbackId, string $message)
    {
        $this->callbackId = $callbackId;
        parent::__construct($message);
    }

    /**
     * @return string The callback identifier.
     */
    public function getWatcherId(): string
    {
        return $this->callbackId;
    }
}
