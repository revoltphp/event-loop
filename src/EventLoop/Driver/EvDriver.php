<?php

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\Callback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\InvalidCallbackError;

final class EvDriver extends AbstractDriver
{
    /** @var \EvSignal[]|null */
    private static ?array $activeSignals = null;

    public static function isSupported(): bool
    {
        return \extension_loaded("ev");
    }

    private \EvLoop $handle;

    /** @var \EvWatcher[] */
    private array $events = [];

    private \Closure $ioCallback;

    private \Closure $timerCallback;

    private \Closure $signalCallback;

    /** @var \EvSignal[] */
    private array $signals = [];

    private bool $dispatchAgain = false;
    private \Closure $dispatchErrorHandler;

    public function __construct()
    {
        parent::__construct();

        $this->handle = new \EvLoop();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (\EvIO $event): void {
            /** @var StreamCallback $callback */
            $callback = $event->data;

            $this->invokeCallback($callback);
        };

        $this->timerCallback = function (\EvTimer $event): void {
            /** @var TimerCallback $callback */
            $callback = $event->data;

            if (!$callback->repeat) {
                $this->cancel($callback->id);
            } else {
                // Disable and re-enable so it's not executed repeatedly in the same tick
                // See https://github.com/amphp/amp/issues/131
                $this->disable($callback->id);
                $this->enable($callback->id);
            }

            $this->invokeCallback($callback);
        };

        $this->signalCallback = function (\EvSignal $event): void {
            /** @var SignalCallback $callback */
            $callback = $event->data;

            $this->invokeCallback($callback);
        };

        $this->dispatchErrorHandler = function ($errno, $message) {
            if ($message === 'EvLoop::run(): Libev error(9): Bad file descriptor') {
                // Retry on bad file descriptors, these watchers are automatically stopped by libev.
                // See https://bitbucket.org/osmanov/pecl-ev/src/0e93974f1c4dbe9e48e30301309220cab2b9c187/php8/watcher.c?at=master#watcher.c-39
                // See EV_ERROR in https://linux.die.net/man/3/ev
                $this->dispatchAgain = true;

                // TODO Provide actual data
                $this->queue(fn () => throw InvalidCallbackError::invalidStream('', 0, fn () => null));

                return;
            }

            throw new \Exception('Unexpected error while running the event loop (ext-ev): ' . $message, $errno);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);
        unset($this->events[$callbackId]);
    }

    public function __destruct()
    {
        foreach ($this->events as $event) {
            /** @psalm-suppress all */
            if ($event !== null) { // Events may have been nulled in extension depending on destruct order.
                $event->stop();
            }
        }

        // We need to clear all references to events manually, see
        // https://bitbucket.org/osmanov/pecl-ev/issues/31/segfault-in-ev_timer_stop
        $this->events = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$activeSignals;

        \assert($active !== null);

        foreach ($active as $event) {
            $event->stop();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            $event->start();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->stop();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                $event->start();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->handle->stop();
        parent::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): \EvLoop
    {
        return $this->handle;
    }

    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        do {
            if ($this->dispatchAgain) {
                $this->dispatchAgain = false;
                $this->invokeMicrotasks();
            }

            \set_error_handler($this->dispatchErrorHandler);

            try {
                // TODO This needs special consideration, because the error handler is now also active for all callbacks
                $this->handle->run($blocking ? \Ev::RUN_ONCE : \Ev::RUN_ONCE | \Ev::RUN_NOWAIT);
            } finally {
                \restore_error_handler();
            }
        } while ($this->dispatchAgain);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $this->handle->nowUpdate();
        $now = $this->now();

        foreach ($callbacks as $callback) {
            $id = $callback->id;

            if (!isset($this->events[$id])) {
                if ($callback instanceof StreamReadableCallback) {
                    if (!\is_resource($callback->stream)) {
                        $this->deactivate($callback);
                        $this->queue(fn () => throw InvalidCallbackError::invalidStream($callback->id, (int) $callback->stream, $callback->callback));
                    } else {
                        $this->events[$id] = $this->handle->io(
                            $callback->stream,
                            \Ev::READ,
                            $this->ioCallback,
                            $callback
                        );
                    }
                } elseif ($callback instanceof StreamWritableCallback) {
                    if (!\is_resource($callback->stream)) {
                        $this->deactivate($callback);
                        $this->queue(fn () => throw InvalidCallbackError::invalidStream($callback->id, (int) $callback->stream, $callback->callback));
                    } else {
                        $this->events[$id] = $this->handle->io(
                            $callback->stream,
                            \Ev::WRITE,
                            $this->ioCallback,
                            $callback
                        );
                    }
                } elseif ($callback instanceof TimerCallback) {
                    $interval = $callback->interval;
                    $this->events[$id] = $this->handle->timer(
                        \max(0, ($callback->expiration - $now)),
                        $callback->repeat ? $interval : 0,
                        $this->timerCallback,
                        $callback
                    );
                } elseif ($callback instanceof SignalCallback) {
                    $this->events[$id] = $this->handle->signal($callback->signal, $this->signalCallback, $callback);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown callback type: " . \get_class($callback));
                    // @codeCoverageIgnoreEnd
                }
            } else {
                // TODO: Check for closed resource?
                $this->events[$id]->start();
            }

            if ($callback instanceof SignalCallback) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->signals[$id] = $this->events[$id];
            }
        }
    }

    protected function deactivate(Callback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->stop();

            if ($callback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
