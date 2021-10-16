<?php

namespace Revolt\EventLoop;

/**
 * MUST be thrown if any callback returns a non-null value.
 */
final class InvalidCallbackError extends \Error
{
    public static function noVoid(string $callbackId, callable $callable): self
    {
        $errorDetail = '';
        if (\is_string($callable)) {
            $errorDetail = " '$callable'";
        } elseif (\is_array($callable) && \count($callable) === 2 && isset($callable[0], $callable[1])) {
            if (\is_string($callable[0]) && \is_string($callable[1])) {
                $errorDetail = " '" . $callable[0] . "::" . $callable[1] . "'";
            } elseif (\is_object($callable[0]) && \is_string($callable[1])) {
                $errorDetail = " '" . \get_class($callable[0]) . "::" . $callable[1] . "'";
            }
        } elseif ($callable instanceof \Closure) {
            try {
                $reflection = new \ReflectionFunction($callable);
                if ($reflection->getFileName() && $reflection->getStartLine()) {
                    $errorDetail = " defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
        }

        return new self($callbackId, 'Non-null return value received from callback' . $errorDetail);
    }

    /** @var string */
    private string $callbackId;

    /**
     * @param string $callbackId The callback identifier.
     * @param string $message The exception message.
     */
    private function __construct(string $callbackId, string $message)
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
