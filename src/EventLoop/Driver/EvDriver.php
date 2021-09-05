<?php

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\SignalWatcher;
use Revolt\EventLoop\Internal\StreamReadWatcher;
use Revolt\EventLoop\Internal\StreamWatcher;
use Revolt\EventLoop\Internal\StreamWriteWatcher;
use Revolt\EventLoop\Internal\TimerWatcher;
use Revolt\EventLoop\Internal\Watcher;

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

    public function __construct()
    {
        parent::__construct();

        $this->handle = new \EvLoop();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (\EvIO $event): void {
            /** @var StreamWatcher $watcher */
            $watcher = $event->data;

            $this->invokeCallback($watcher);
        };

        $this->timerCallback = function (\EvTimer $event): void {
            /** @var TimerWatcher $watcher */
            $watcher = $event->data;

            if (!$watcher->repeat) {
                $this->cancel($watcher->id);
            } else {
                // Disable and re-enable so it's not executed repeatedly in the same tick
                // See https://github.com/amphp/amp/issues/131
                $this->disable($watcher->id);
                $this->enable($watcher->id);
            }

            $this->invokeCallback($watcher);
        };

        $this->signalCallback = function (\EvSignal $event): void {
            /** @var SignalWatcher $watcher */
            $watcher = $event->data;

            $this->invokeCallback($watcher);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $watcherId): void
    {
        parent::cancel($watcherId);
        unset($this->events[$watcherId]);
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
    protected function activate(array $watchers): void
    {
        $this->handle->nowUpdate();
        $now = $this->now();

        foreach ($watchers as $watcher) {
            if (!isset($this->events[$id = $watcher->id])) {
                if ($watcher instanceof StreamReadWatcher) {
                    \assert(\is_resource($watcher->stream));

                    $this->events[$id] = $this->handle->io($watcher->stream, \Ev::READ, $this->ioCallback, $watcher);
                } elseif ($watcher instanceof StreamWriteWatcher) {
                    \assert(\is_resource($watcher->stream));

                    $this->events[$id] = $this->handle->io(
                        $watcher->stream,
                        \Ev::WRITE,
                        $this->ioCallback,
                        $watcher
                    );
                } elseif ($watcher instanceof TimerWatcher) {
                    $interval = $watcher->interval;
                    $this->events[$id] = $this->handle->timer(
                        \max(0, ($watcher->expiration - $now)),
                        $watcher->repeat ? $interval : 0,
                        $this->timerCallback,
                        $watcher
                    );
                } elseif ($watcher instanceof SignalWatcher) {
                    $this->events[$id] = $this->handle->signal($watcher->signal, $this->signalCallback, $watcher);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown watcher type: " . \get_class($watcher));
                    // @codeCoverageIgnoreEnd
                }
            } else {
                $this->events[$id]->start();
            }

            if ($watcher instanceof SignalWatcher) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->signals[$id] = $this->events[$id];
            }
        }
    }

    protected function deactivate(Watcher $watcher): void
    {
        if (isset($this->events[$id = $watcher->id])) {
            $this->events[$id]->stop();

            if ($watcher instanceof SignalWatcher) {
                unset($this->signals[$id]);
            }
        }
    }
}
