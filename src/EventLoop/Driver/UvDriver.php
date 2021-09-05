<?php

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\SignalWatcher;
use Revolt\EventLoop\Internal\StreamReadWatcher;
use Revolt\EventLoop\Internal\StreamWatcher;
use Revolt\EventLoop\Internal\StreamWriteWatcher;
use Revolt\EventLoop\Internal\TimerWatcher;
use Revolt\EventLoop\Internal\Watcher;

final class UvDriver extends AbstractDriver
{
    public static function isSupported(): bool
    {
        return \extension_loaded("uv");
    }

    /** @var resource|\UVLoop A uv_loop resource created with uv_loop_new() */
    private $handle;
    /** @var resource[] */
    private array $events = [];
    /** @var Watcher[][] */
    private array $watchers = [];
    /** @var resource[] */
    private array $streams = [];
    private \Closure $ioCallback;
    private \Closure $timerCallback;
    private \Closure $signalCallback;

    public function __construct()
    {
        parent::__construct();

        $this->handle = \uv_loop_new();

        $this->ioCallback = function ($event, $status, $events, $resource): void {
            $watchers = $this->watchers[(int) $event];

            // Invoke the callback on errors, as this matches behavior with other loop back-ends.
            // Re-enable watcher as libuv disables the watcher on non-zero status.
            if ($status !== 0) {
                $flags = 0;
                foreach ($watchers as $watcher) {
                    \assert($watcher instanceof StreamWatcher);

                    $flags |= $watcher->enabled ? $this->getStreamWatcherFlags($watcher) : 0;
                }
                \uv_poll_start($event, $flags, $this->ioCallback);
            }

            foreach ($watchers as $watcher) {
                \assert($watcher instanceof StreamWatcher);

                // $events is ORed with 4 to trigger watcher if no events are indicated (0) or on UV_DISCONNECT (4).
                // http://docs.libuv.org/en/v1.x/poll.html
                if (!($watcher->enabled && ($this->getStreamWatcherFlags($watcher) & $events || ($events | 4) === 4))) {
                    continue;
                }

                $this->invokeCallback($watcher);
            }
        };

        $this->timerCallback = function ($event): void {
            $watcher = $this->watchers[(int) $event][0];

            \assert($watcher instanceof TimerWatcher);

            if (!$watcher->repeat) {
                unset($this->events[$watcher->id], $this->watchers[(int) $event]); // Avoid call to uv_is_active().
                $this->cancel($watcher->id); // Remove reference to watcher in parent.
            } else {
                // Disable and re-enable so it's not executed repeatedly in the same tick
                // See https://github.com/amphp/amp/issues/131
                $this->disable($watcher->id);
                $this->enable($watcher->id);
            }

            $this->invokeCallback($watcher);
        };

        $this->signalCallback = function ($event, $signo): void {
            $watcher = $this->watchers[(int) $event][0];

            $this->invokeCallback($watcher);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $watcherId): void
    {
        parent::cancel($watcherId);

        if (!isset($this->events[$watcherId])) {
            return;
        }

        $event = $this->events[$watcherId];
        $eventId = (int) $event;

        if (isset($this->watchers[$eventId][0])) { // All except IO watchers.
            unset($this->watchers[$eventId]);
        } elseif (isset($this->watchers[$eventId][$watcherId])) {
            $watcher = $this->watchers[$eventId][$watcherId];
            unset($this->watchers[$eventId][$watcherId]);

            \assert($watcher instanceof StreamWatcher);

            if (empty($this->watchers[$eventId])) {
                unset($this->watchers[$eventId], $this->streams[(int) $watcher->stream]);
            }
        }

        unset($this->events[$watcherId]);
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): mixed
    {
        return $this->handle;
    }

    protected function now(): float
    {
        \uv_update_time($this->handle);

        /** @psalm-suppress TooManyArguments */
        return \uv_now($this->handle) / 1000;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        /** @psalm-suppress TooManyArguments */
        \uv_run($this->handle, $blocking ? \UV::RUN_ONCE : \UV::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers): void
    {
        $now = $this->now();

        foreach ($watchers as $watcher) {
            $id = $watcher->id;

            if ($watcher instanceof StreamWatcher) {
                \assert(\is_resource($watcher->stream));

                $streamId = (int) $watcher->stream;

                if (isset($this->streams[$streamId])) {
                    $event = $this->streams[$streamId];
                } elseif (isset($this->events[$id])) {
                    $event = $this->streams[$streamId] = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->streams[$streamId] = \uv_poll_init_socket($this->handle, $watcher->stream);
                }

                $eventId = (int) $event;
                $this->events[$id] = $event;
                $this->watchers[$eventId][$id] = $watcher;

                $flags = 0;
                foreach ($this->watchers[$eventId] as $w) {
                    \assert($w instanceof StreamWatcher);

                    $flags |= $w->enabled ? ($this->getStreamWatcherFlags($w)) : 0;
                }
                \uv_poll_start($event, $flags, $this->ioCallback);
            } elseif ($watcher instanceof TimerWatcher) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    $event = $this->events[$id] = \uv_timer_init($this->handle);
                }

                $this->watchers[(int) $event] = [$watcher];

                \uv_timer_start(
                    $event,
                    (int) \ceil(\max(0, $watcher->expiration - $now) * 1000),
                    $watcher->repeat ? (int) \ceil($watcher->interval * 1000) : 0,
                    $this->timerCallback
                );
            } elseif ($watcher instanceof SignalWatcher) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->events[$id] = \uv_signal_init($this->handle);
                }

                $this->watchers[(int) $event] = [$watcher];

                /** @psalm-suppress TooManyArguments */
                \uv_signal_start($event, $this->signalCallback, $watcher->signal);
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown watcher type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(Watcher $watcher): void
    {
        $id = $watcher->id;

        if (!isset($this->events[$id])) {
            return;
        }

        $event = $this->events[$id];

        if (!\uv_is_active($event)) {
            return;
        }

        if ($watcher instanceof StreamWatcher) {
            $flags = 0;
            foreach ($this->watchers[(int) $event] as $w) {
                \assert($w instanceof StreamWatcher);

                $flags |= $w->enabled ? ($this->getStreamWatcherFlags($w)) : 0;
            }

            if ($flags) {
                \uv_poll_start($event, $flags, $this->ioCallback);
            } else {
                \uv_poll_stop($event);
            }
        } elseif ($watcher instanceof TimerWatcher) {
            \uv_timer_stop($event);
        } elseif ($watcher instanceof SignalWatcher) {
            \uv_signal_stop($event);
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown watcher type");
            // @codeCoverageIgnoreEnd
        }
    }

    private function getStreamWatcherFlags(StreamWatcher $watcher): int
    {
        if ($watcher instanceof StreamWriteWatcher) {
            return \UV::WRITABLE;
        }

        if ($watcher instanceof StreamReadWatcher) {
            return \UV::READABLE;
        }

        throw new \Error('Invalid watcher type');
    }
}
