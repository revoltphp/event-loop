<?php

// Provided by PHP >= 8.6, or by symfony/polyfill-io-poll below it.

namespace Io {
    class IoException extends \Exception {}
}

namespace Io\Poll {
    enum Backend
    {
        case Auto;
        case Poll;
        case Epoll;
        case Kqueue;
        case EventPorts;
        case WSAPoll;

        /** @return list<Backend> */
        public static function getAvailableBackends(): array {}

        public function isAvailable(): bool {}

        public function supportsEdgeTriggering(): bool {}
    }

    enum Event
    {
        case Read;
        case Write;
        case Error;
        case HangUp;
        case ReadHangUp;
        case OneShot;
        case EdgeTriggered;
    }

    interface Handle {}

    final class Watcher
    {
        public function getHandle(): Handle {}

        /** @return list<Event> */
        public function getWatchedEvents(): array {}

        /** @return list<Event> */
        public function getTriggeredEvents(): array {}

        public function getData(): mixed {}

        public function hasTriggered(Event $event): bool {}

        public function isActive(): bool {}

        /** @param list<Event> $events */
        public function modify(array $events, mixed $data = null): void {}

        /** @param list<Event> $events */
        public function modifyEvents(array $events): void {}

        public function modifyData(mixed $data): void {}

        public function remove(): void {}
    }

    final class Context
    {
        public function __construct(Backend $backend = Backend::Auto) {}

        /** @param list<Event> $events */
        public function add(Handle $handle, array $events, mixed $data = null): Watcher {}

        /** @return list<Watcher> */
        public function wait(?\Time\Duration $timeout = null, ?int $maxEvents = null): array {}

        public function getBackend(): Backend {}
    }

    class PollException extends \Io\IoException {}

    abstract class FailedPollOperationException extends PollException
    {
        public const int ERROR_NONE = 0;
        public const int ERROR_SYSTEM = 1;
        public const int ERROR_NOMEM = 2;
        public const int ERROR_INVALID = 3;
        public const int ERROR_EXISTS = 4;
        public const int ERROR_NOTFOUND = 5;
        public const int ERROR_TIMEOUT = 6;
        public const int ERROR_INTERRUPTED = 7;
        public const int ERROR_PERMISSION = 8;
        public const int ERROR_TOOBIG = 9;
        public const int ERROR_AGAIN = 10;
        public const int ERROR_NOSUPPORT = 11;
    }

    class FailedContextInitializationException extends FailedPollOperationException {}
    class FailedHandleAddException extends FailedPollOperationException {}
    class FailedWatcherModificationException extends FailedPollOperationException {}
    class FailedPollWaitException extends FailedPollOperationException {}
    class BackendUnavailableException extends PollException {}
    class InactiveWatcherException extends PollException {}
    class HandleAlreadyWatchedException extends PollException {}
    class InvalidHandleException extends PollException {}
}

namespace Time {
    final class Duration
    {
        public readonly int $seconds;
        public readonly int $nanoseconds;
        public readonly bool $negative;

        public static function fromSeconds(int $seconds, int $nanoseconds = 0): self {}
    }
}

namespace {
    final class StreamPollHandle implements Io\Poll\Handle
    {
        /** @param resource $stream */
        public function __construct($stream) {}

        /** @return resource */
        public function getStream() {}

        public function isValid(): bool {}
    }
}
