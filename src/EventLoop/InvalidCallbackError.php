<?php

namespace Revolt\EventLoop;

final class InvalidCallbackError extends \Error
{
    public const E_NONNULL_RETURN = 1;
    public const E_INVALID_IDENTIFIER = 2;
    public const E_INVALID_STREAM = 3;

    /**
     * MUST be thrown if any callback returns a non-null value.
     */
    public static function nonNullReturn(string $callbackId, callable $callable): self
    {
        $description = self::getCallableDescription($callable);

        return new self(
            $callbackId,
            self::E_NONNULL_RETURN,
            'Non-null return value received from callback ' . $description
        );
    }

    /**
     * MUST be thrown if any operation (except disable() and cancel()) is attempted with an invalid callback identifier.
     *
     * An invalid callback identifier is any identifier that is not yet emitted by the driver or cancelled by the user.
     */
    public static function invalidIdentifier(string $callbackId): self
    {
        return new self($callbackId, self::E_INVALID_IDENTIFIER, 'Invalid callback identifier ' . $callbackId);
    }

    /**
     * MUST be thrown if a closed stream resource is detected for an active stream callback.
     */
    public static function invalidStream(string $callbackId, int $streamId, callable $callable): self
    {
        $description = self::getCallableDescription($callable);

        return new self(
            $callbackId,
            self::E_INVALID_STREAM,
            'Detected closed stream resource for callback ' . $description . '; Please ensure to cancel related stream callbacks before closing stream resources.'
        );
    }

    private static function getCallableDescription(callable $callable): string
    {
        if (\is_string($callable)) {
            return $callable;
        }

        if (\is_array($callable) && \count($callable) === 2 && isset($callable[0], $callable[1])) {
            if (\is_string($callable[0]) && \is_string($callable[1])) {
                return $callable[0] . "::" . $callable[1];
            }

            if (\is_object($callable[0]) && \is_string($callable[1])) {
                return \get_class($callable[0]) . "::" . $callable[1];
            }

            return '???';
        }

        if ($callable instanceof \Closure) {
            try {
                $reflection = new \ReflectionFunction($callable);
                if ($reflection->getFileName() && $reflection->getStartLine()) {
                    return "defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
                }
            } catch (\ReflectionException) {
                // ignore
            }
        }

        if (\is_object($callable) && \method_exists($callable, '__invoke')) {
            return \get_class($callable) . '::__invoke';
        }

        return '???';
    }

    /** @var string */
    private string $rawMessage;

    /** @var string */
    private string $callbackId;

    /** @var string[] */
    private array $info = [];

    /**
     * @param string $callbackId The callback identifier.
     * @param string $message The exception message.
     */
    private function __construct(string $callbackId, int $code, string $message)
    {
        parent::__construct($message, $code);

        $this->callbackId = $callbackId;
        $this->rawMessage = $message;
    }

    /**
     * @return string The callback identifier.
     */
    public function getCallbackId(): string
    {
        return $this->callbackId;
    }

    public function addInfo(string $key, string $message): void
    {
        $this->info[$key] = $message;

        $info = '';

        foreach ($this->info as $infoKey => $infoMessage) {
            $info .= "\r\n\r\n" . $infoKey . ': ' . $infoMessage;
        }

        $this->message = $this->rawMessage . $info;
    }
}
