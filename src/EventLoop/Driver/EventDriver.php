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

final class EventDriver extends AbstractDriver
{
    /** @var \Event[]|null */
    private static ?array $activeSignals = null;

    public static function isSupported(): bool
    {
        return \extension_loaded("event");
    }

    private \EventBase $handle;
    /** @var \Event[] */
    private array $events = [];
    private \Closure $ioCallback;
    private \Closure $timerCallback;
    private \Closure $signalCallback;
    private array $signals = [];

    public function __construct()
    {
        parent::__construct();

        /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
        $this->handle = new \EventBase();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function ($resource, $what, StreamWatcher $watcher): void {
            \assert(\is_resource($watcher->stream));

            $this->invokeCallback($watcher);
        };

        $this->timerCallback = function ($resource, $what, TimerWatcher $watcher): void {
            if ($watcher->repeat) {
                $this->events[$watcher->id]->add($watcher->interval);
            } else {
                $this->cancel($watcher->id);
            }

            $this->invokeCallback($watcher);
        };

        $this->signalCallback = function ($signo, $what, SignalWatcher $watcher): void {
            $this->invokeCallback($watcher);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $watcherId): void
    {
        parent::cancel($watcherId);

        if (isset($this->events[$watcherId])) {
            $this->events[$watcherId]->free();
            unset($this->events[$watcherId]);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        foreach ($this->events as $event) {
            if ($event !== null) { // Events may have been nulled in extension depending on destruct order.
                $event->free();
            }
        }

        // Unset here, otherwise $event->del() fails with a warning, because __destruct order isn't defined.
        // See https://github.com/amphp/amp/issues/159.
        $this->events = [];

        // Manually free the loop handle to fully release loop resources.
        // See https://github.com/amphp/amp/issues/177.
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (isset($this->handle)) {
            $this->handle->free();
            unset($this->handle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$activeSignals;

        \assert($active !== null);

        foreach ($active as $event) {
            $event->del();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
            $event->add();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->del();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $event->add();
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
    public function getHandle(): \EventBase
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
        $this->handle->loop($blocking ? \EventBase::LOOP_ONCE : \EventBase::LOOP_ONCE | \EventBase::LOOP_NONBLOCK);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers): void
    {
        $now = $this->now();

        foreach ($watchers as $watcher) {
            if (!isset($this->events[$id = $watcher->id])) {
                if ($watcher instanceof StreamReadWatcher) {
                    \assert(\is_resource($watcher->stream));

                    $this->events[$id] = new \Event(
                        $this->handle,
                        $watcher->stream,
                        \Event::READ | \Event::PERSIST,
                        $this->ioCallback,
                        $watcher
                    );
                } elseif ($watcher instanceof StreamWriteWatcher) {
                    \assert(\is_resource($watcher->stream));

                    $this->events[$id] = new \Event(
                        $this->handle,
                        $watcher->stream,
                        \Event::WRITE | \Event::PERSIST,
                        $this->ioCallback,
                        $watcher
                    );
                } elseif ($watcher instanceof TimerWatcher) {
                    $this->events[$id] = new \Event(
                        $this->handle,
                        -1,
                        \Event::TIMEOUT,
                        $this->timerCallback,
                        $watcher
                    );
                } elseif ($watcher instanceof SignalWatcher) {
                    $this->events[$id] = new \Event(
                        $this->handle,
                        $watcher->signal,
                        \Event::SIGNAL | \Event::PERSIST,
                        $this->signalCallback,
                        $watcher
                    );
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown watcher type");
                    // @codeCoverageIgnoreEnd
                }
            }

            if ($watcher instanceof TimerWatcher) {
                $interval = \max(0, $watcher->expiration - $now);
                $this->events[$id]->add($interval > 0 ? $interval : 0);
            } elseif ($watcher instanceof SignalWatcher) {
                $this->signals[$id] = $this->events[$id];
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            } else {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(Watcher $watcher): void
    {
        if (isset($this->events[$id = $watcher->id])) {
            $this->events[$id]->del();

            if ($watcher instanceof SignalWatcher) {
                unset($this->signals[$id]);
            }
        }
    }
}
