<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedHandleAddException;
use Io\Poll\FailedPollOperationException;
use Io\Poll\FailedPollWaitException;
use Io\Poll\HandleAlreadyWatchedException;
use Io\Poll\InvalidHandleException;
use Io\Poll\PollException;
use Io\Poll\Watcher;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\Internal\TimerQueue;
use Revolt\EventLoop\UnsupportedFeatureException;
use Time\Duration;

final class IoPollDriver extends AbstractDriver
{
    /**
     * Whether this driver is worth choosing over StreamSelectDriver.
     *
     * The Io\Poll API ships with every PHP 8.6 build. The driver also runs on symfony/polyfill-io-poll
     * below that, but that polyfill is backed by stream_select() itself, so it is slower than using
     * stream_select() directly and is not picked automatically. Set REVOLT_DRIVER to this class to
     * use it there anyway.
     */
    public static function isSupported(): bool
    {
        return \PHP_VERSION_ID >= 80600;
    }

    private readonly Context $context;

    /** @var array<int, Watcher> One watcher per stream: the poll context keys them by file descriptor. */
    private array $watchers = [];

    /**
     * Context for the streams the preferred backend refuses. epoll and kqueue only accept handles that
     * implement polling, while regular files, /dev/null and the like are accepted by poll(), which
     * reports them as always ready, the way stream_select() does.
     */
    private ?Context $alwaysReadyContext = null;

    /** @var array<int, true> Streams watched in the context above, keyed by stream id. */
    private array $alwaysReadyStreams = [];

    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $readCallbacks = [];

    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $writeCallbacks = [];

    private readonly TimerQueue $timerQueue;

    /** @var array<int, array<string, SignalCallback>> */
    private array $signalCallbacks = [];

    /** @var \SplQueue<int> */
    private readonly \SplQueue $signalQueue;

    private bool $signalHandling;

    public function __construct()
    {
        parent::__construct();

        $this->context = new Context();
        $this->signalQueue = new \SplQueue();
        $this->timerQueue = new TimerQueue();
        $this->signalHandling = \extension_loaded("pcntl")
            && \function_exists('pcntl_signal_dispatch')
            && \function_exists('pcntl_signal');
    }

    public function __destruct()
    {
        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    /**
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    #[\Override]
    public function onSignal(int $signal, \Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signal, $closure);
    }

    #[\Override]
    public function getHandle(): Context
    {
        return $this->context;
    }

    #[\Override]
    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    protected function dispatch(bool $blocking): void
    {
        if ($this->signalHandling) {
            \pcntl_signal_dispatch();

            while (!$this->signalQueue->isEmpty()) {
                $signal = $this->signalQueue->dequeue();

                foreach ($this->signalCallbacks[$signal] as $callback) {
                    $this->enqueueCallback($callback);
                }

                $blocking = false;
            }
        }

        $this->poll($blocking ? $this->getTimeout() : 0.0);

        $now = $this->now();

        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    #[\Override]
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->watch($streamId, $callback->stream);
            } elseif ($callback instanceof StreamWritableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->watch($streamId, $callback->stream);
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            \sprintf("Failed to register signal handler; Errno: %d; %s", $errno, $errstr)
                        );
                    });

                    // Avoid bug in Psalm handling of first-class callables by assigning to a temp variable.
                    $handler = $this->handleSignal(...);

                    try {
                        \pcntl_signal($callback->signal, $handler);
                    } finally {
                        \restore_error_handler();
                    }
                }

                $this->signalCallbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    #[\Override]
    protected function deactivate(DriverCallback $callback): void
    {
        if ($callback instanceof StreamReadableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->readCallbacks[$streamId][$callback->id]);
            if (empty($this->readCallbacks[$streamId])) {
                unset($this->readCallbacks[$streamId]);
            }

            $this->watch($streamId, $callback->stream);
        } elseif ($callback instanceof StreamWritableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId]);
            }

            $this->watch($streamId, $callback->stream);
        } elseif ($callback instanceof TimerCallback) {
            $this->timerQueue->remove($callback);
        } elseif ($callback instanceof SignalCallback) {
            if (isset($this->signalCallbacks[$callback->signal])) {
                unset($this->signalCallbacks[$callback->signal][$callback->id]);

                if (empty($this->signalCallbacks[$callback->signal])) {
                    unset($this->signalCallbacks[$callback->signal]);
                    \set_error_handler(static fn () => true);
                    try {
                        \pcntl_signal($callback->signal, \SIG_DFL);
                    } finally {
                        \restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Brings the watcher of the given stream in line with the callbacks enabled on it.
     */
    private function watch(int $streamId, mixed $stream): void
    {
        $events = [];
        if (isset($this->readCallbacks[$streamId])) {
            $events[] = Event::Read;
        }
        if (isset($this->writeCallbacks[$streamId])) {
            $events[] = Event::Write;
        }

        $watcher = $this->watchers[$streamId] ?? null;

        if (!$events || !\is_resource($stream)) {
            if ($watcher !== null) {
                unset($this->watchers[$streamId], $this->alwaysReadyStreams[$streamId]);

                // Removing the watcher of a closed stream crashes 8.6 builds before php/php-src#23791
                if (\is_resource($stream)) {
                    $watcher->remove();
                }
            }

            return;
        }

        try {
            if ($watcher !== null) {
                $watcher->modifyEvents($events);

                return;
            }

            $handle = new \StreamPollHandle($stream);

            try {
                $this->watchers[$streamId] = $this->context->add($handle, $events, $streamId);
            } catch (FailedHandleAddException $exception) {
                $this->alwaysReadyContext ??= new Context(Backend::Poll);

                try {
                    $this->watchers[$streamId] = $this->alwaysReadyContext->add($handle, $events, $streamId);
                } catch (PollException) {
                    throw $exception; // the refusal of the preferred backend is the one worth reporting
                }

                $this->alwaysReadyStreams[$streamId] = true;
            }
        } catch (HandleAlreadyWatchedException | InvalidHandleException $exception) {
            throw new \Error(
                "Polling the stream failed: ensure all callbacks on closed stream resources are cancelled",
                previous: $exception,
            );
        }
    }

    private function poll(?float $timeout): void
    {
        if (!$this->watchers) {
            if ($timeout === null) { // Only signal callbacks are enabled, so sleep indefinitely.
                /** @psalm-suppress ArgumentTypeCoercion */
                \usleep(\PHP_INT_MAX);
                return;
            }

            if ($timeout > 0) { // Sleep until next timer expires.
                /** @psalm-suppress ArgumentTypeCoercion $timeout is positive here. */
                \usleep((int) ($timeout * 1_000_000));
            }

            return;
        }

        if ($this->alwaysReadyStreams) {
            // Those streams are ready by definition, so there is nothing to wait for.
            $timeout = 0.0;
        }

        if ($timeout === null) {
            $duration = null;
        } else {
            $seconds = (int) $timeout;
            $duration = Duration::fromSeconds($seconds, (int) (($timeout - $seconds) * 1_000_000_000));
        }

        try {
            $watchers = $this->context->wait($duration);
        } catch (FailedPollWaitException $exception) {
            if ($exception->getCode() !== FailedPollOperationException::ERROR_INTERRUPTED) {
                throw $exception;
            }

            return; // A signal arrived, it is dispatched at the start of the next tick.
        }

        if ($this->alwaysReadyContext !== null) {
            $watchers = [...$watchers, ...$this->alwaysReadyContext->wait(Duration::fromSeconds(0))];
        }

        foreach ($watchers as $watcher) {
            /** @var int $streamId */
            $streamId = $watcher->getData();

            // Error and HangUp are reported whether they were requested or not. Both sides have to be
            // woken up on them, or a level-triggered backend reports them again on every wait().
            $aborted = $watcher->hasTriggered(Event::Error) || $watcher->hasTriggered(Event::HangUp);

            if ($aborted || $watcher->hasTriggered(Event::Read)) {
                foreach ($this->readCallbacks[$streamId] ?? [] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            if ($aborted || $watcher->hasTriggered(Event::Write)) {
                foreach ($this->writeCallbacks[$streamId] ?? [] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }
        }
    }

    /**
     * @return float|null Seconds until next timer expires or null if there are no pending timers.
     */
    private function getTimeout(): ?float
    {
        $expiration = $this->timerQueue->peek();

        if ($expiration === null) {
            return null;
        }

        $expiration -= $this->now();

        return $expiration > 0 ? $expiration : 0.0;
    }

    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs
        $this->signalQueue->enqueue($signal);
    }
}
