<?php

declare(strict_types=1);

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;

final class EvDriver extends AbstractDriver
{
    /** @var array<string, \EvSignal>|null */
    private static ?array $activeSignals = null;

    public static function isSupported(): bool
    {
        return \extension_loaded("ev");
    }

    private \EvLoop $handle;

    /** @var array<string, \EvWatcher> */
    private array $events = [];

    private readonly \Closure $ioCallback;

    private readonly \Closure $timerCallback;

    private readonly \Closure $signalCallback;

    /** @var array<string, \EvSignal> */
    private array $signals = [];

    public function __construct()
    {
        parent::__construct();

        $this->handle = new \EvLoop();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (\EvIo $event): void {
            /** @var StreamCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };

        $this->timerCallback = function (\EvTimer $event): void {
            /** @var TimerCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };

        $this->signalCallback = function (\EvSignal $event): void {
            /** @var SignalCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
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
        $this->handle->run($blocking ? \Ev::RUN_ONCE : \Ev::RUN_ONCE | \Ev::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $this->handle->nowUpdate();
        $now = $this->now();

        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof StreamReadableCallback) {
                    \assert(\is_resource($callback->stream));

                    $this->events[$id] = $this->handle->io($callback->stream, \Ev::READ, $this->ioCallback, $callback);
                } elseif ($callback instanceof StreamWritableCallback) {
                    \assert(\is_resource($callback->stream));

                    $this->events[$id] = $this->handle->io(
                        $callback->stream,
                        \Ev::WRITE,
                        $this->ioCallback,
                        $callback
                    );
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
                $this->events[$id]->start();
            }

            if ($callback instanceof SignalCallback) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->signals[$id] = $this->events[$id];
            }
        }
    }

    protected function deactivate(DriverCallback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->stop();

            if ($callback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
