<?php

namespace Revolt\EventLoop;

final class InvalidCallbackError extends \Error
{
    public const E_NONNULL_RETURN = 1;
    public const E_INVALID_IDENTIFIER = 2;

    /**
     * MUST be thrown if any callback returns a non-null value.
     */
    public static function nonNullReturn(string $callbackId, \Closure $closure): self
    {
        $description = self::getClosureDescription($closure);

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

    private static function getClosureDescription(\Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);

            $description = $reflection->name;

            if ($scopeClass = $reflection->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            if ($reflection->getFileName() && $reflection->getStartLine()) {
                $description .= " defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
            }

            return $description;
        } catch (\ReflectionException) {
            return '???';
        }
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
