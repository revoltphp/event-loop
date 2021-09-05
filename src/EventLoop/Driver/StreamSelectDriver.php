<?php

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\SignalWatcher;
use Revolt\EventLoop\Internal\StreamReadWatcher;
use Revolt\EventLoop\Internal\StreamWriteWatcher;
use Revolt\EventLoop\Internal\TimerQueue;
use Revolt\EventLoop\Internal\TimerWatcher;
use Revolt\EventLoop\Internal\Watcher;
use Revolt\EventLoop\UnsupportedFeatureException;

final class StreamSelectDriver extends AbstractDriver
{
    /** @var resource[]|object[] */
    private array $readStreams = [];

    /** @var StreamReadWatcher[][] */
    private array $readWatchers = [];

    /** @var resource[]|object[] */
    private array $writeStreams = [];

    /** @var StreamWriteWatcher[][] */
    private array $writeWatchers = [];

    private TimerQueue $timerQueue;

    /** @var SignalWatcher[][] */
    private array $signalWatchers = [];

    private bool $signalHandling;

    private \Closure $streamSelectErrorHandler;

    private bool $streamSelectIgnoreResult = false;

    public function __construct()
    {
        parent::__construct();

        $this->timerQueue = new TimerQueue();
        $this->signalHandling = \extension_loaded("pcntl");

        $this->streamSelectErrorHandler = function ($errno, $message) {
            // Casing changed in PHP 8 from 'unable' to 'Unable'
            if (\stripos($message, "stream_select(): unable to select [4]: ") === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }

            if (\str_contains($message, 'FD_SETSIZE')) {
                $message = \str_replace(["\r\n", "\n", "\r"], " ", $message);
                $pattern = '(stream_select\(\): You MUST recompile PHP with a larger value of FD_SETSIZE. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)\.)';

                if (\preg_match($pattern, $message, $match)) {
                    $helpLink = 'https://github.com/revoltphp/event-loop/tree/main/docs#implementations';

                    $message = 'You have reached the limits of stream_select(). It has a FD_SETSIZE of ' . $match[1]
                        . ', but you have file descriptors numbered at least as high as ' . $match[2] . '. '
                        . "You can install one of the extensions listed on {$helpLink} to support a higher number of "
                        . "concurrent file descriptors. If a large number of open file descriptors is unexpected, you "
                        . "might be leaking file descriptors that aren't closed correctly.";
                }
            }

            throw new \Exception($message, $errno);
        };
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    public function onSignal(int $signo, callable $callback): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signo, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): mixed
    {
        return null;
    }

    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * @throws \Throwable
     */
    protected function dispatch(bool $blocking): void
    {
        $this->selectStreams(
            $this->readStreams,
            $this->writeStreams,
            $blocking ? $this->getTimeout() : 0.0
        );

        $now = $this->now();

        while ($watcher = $this->timerQueue->extract($now)) {
            if ($watcher->repeat) {
                $watcher->enabled = false; // Trick base class into adding to enable queue when calling enable()
                $this->enable($watcher->id);
            } else {
                $this->cancel($watcher->id);
            }

            $this->invokeCallback($watcher);
        }

        if ($this->signalHandling) {
            \pcntl_signal_dispatch();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers): void
    {
        foreach ($watchers as $watcher) {
            if ($watcher instanceof StreamReadWatcher) {
                \assert(\is_resource($watcher->stream));

                $streamId = (int) $watcher->stream;
                $this->readWatchers[$streamId][$watcher->id] = $watcher;
                $this->readStreams[$streamId] = $watcher->stream;
            } elseif ($watcher instanceof StreamWriteWatcher) {
                \assert(\is_resource($watcher->stream));

                $streamId = (int) $watcher->stream;
                $this->writeWatchers[$streamId][$watcher->id] = $watcher;
                $this->writeStreams[$streamId] = $watcher->stream;
            } elseif ($watcher instanceof TimerWatcher) {
                $this->timerQueue->insert($watcher);
            } elseif ($watcher instanceof SignalWatcher) {
                if (!isset($this->signalWatchers[$watcher->signal])) {
                    if (!@\pcntl_signal($watcher->signal, \Closure::fromCallable([$this, 'handleSignal']))) {
                        $message = "Failed to register signal handler";
                        if ($error = \error_get_last()) {
                            $message .= \sprintf("; Errno: %d; %s", $error["type"], $error["message"]);
                        }
                        throw new \Error($message);
                    }
                }

                $this->signalWatchers[$watcher->signal][$watcher->id] = $watcher;
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
        if ($watcher instanceof StreamReadWatcher) {
            $streamId = (int) $watcher->stream;
            unset($this->readWatchers[$streamId][$watcher->id]);
            if (empty($this->readWatchers[$streamId])) {
                unset($this->readWatchers[$streamId], $this->readStreams[$streamId]);
            }
        } elseif ($watcher instanceof StreamWriteWatcher) {
            $streamId = (int) $watcher->stream;
            unset($this->writeWatchers[$streamId][$watcher->id]);
            if (empty($this->writeWatchers[$streamId])) {
                unset($this->writeWatchers[$streamId], $this->writeStreams[$streamId]);
            }
        } elseif ($watcher instanceof TimerWatcher) {
            $this->timerQueue->remove($watcher);
        } elseif ($watcher instanceof SignalWatcher) {
            if (isset($this->signalWatchers[$watcher->signal])) {
                unset($this->signalWatchers[$watcher->signal][$watcher->id]);

                if (empty($this->signalWatchers[$watcher->signal])) {
                    unset($this->signalWatchers[$watcher->signal]);
                    @\pcntl_signal($watcher->signal, \SIG_DFL);
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown watcher type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @param resource[]|object[] $read
     * @param resource[]|object[] $write
     */
    private function selectStreams(array $read, array $write, float $timeout): void
    {
        if (!empty($read) || !empty($write)) { // Use stream_select() if there are any streams in the loop.
            if ($timeout >= 0) {
                $seconds = (int) $timeout;
                $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            } else {
                $seconds = null;
                $microseconds = null;
            }

            // Failed connection attempts are indicated via except on Windows
            // @link https://github.com/reactphp/event-loop/blob/8bd064ce23c26c4decf186c2a5a818c9a8209eb0/src/StreamSelectLoop.php#L279-L287
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (\DIRECTORY_SEPARATOR === '\\') {
                $except = $write;
            }

            \set_error_handler($this->streamSelectErrorHandler);

            try {
                /** @psalm-suppress InvalidArgument */
                $result = \stream_select($read, $write, $except, $seconds, $microseconds);
            } finally {
                \restore_error_handler();
            }

            if ($this->streamSelectIgnoreResult || $result === 0) {
                $this->streamSelectIgnoreResult = false;
                return;
            }

            if (!$result) {
                $this->error(new \Exception('Unknown error during stream_select'));
                return;
            }

            foreach ($read as $stream) {
                $streamId = (int) $stream;
                if (!isset($this->readWatchers[$streamId])) {
                    continue; // All read watchers disabled.
                }

                foreach ($this->readWatchers[$streamId] as $watcher) {
                    if (!isset($this->readWatchers[$streamId][$watcher->id])) {
                        continue; // Watcher disabled by another IO watcher.
                    }

                    $this->invokeCallback($watcher);
                }
            }

            \assert(\is_array($write)); // See https://github.com/vimeo/psalm/issues/3036

            if ($except) {
                foreach ($except as $key => $socket) {
                    $write[$key] = $socket;
                }
            }

            foreach ($write as $stream) {
                $streamId = (int) $stream;
                if (!isset($this->writeWatchers[$streamId])) {
                    continue; // All write watchers disabled.
                }

                foreach ($this->writeWatchers[$streamId] as $watcher) {
                    if (!isset($this->writeWatchers[$streamId][$watcher->id])) {
                        continue; // Watcher disabled by another IO watcher.
                    }

                    $this->invokeCallback($watcher);
                }
            }

            return;
        }

        if ($timeout < 0) { // Only signal watchers are enabled, so sleep indefinitely.
            \usleep(\PHP_INT_MAX);
            return;
        }

        if ($timeout > 0) { // Sleep until next timer expires.
            \usleep((int) ($timeout * 1_000_000));
        }
    }

    /**
     * @return float Seconds until next timer expires or -1 if there are no pending timers.
     */
    private function getTimeout(): float
    {
        $expiration = $this->timerQueue->peek();

        if ($expiration === null) {
            return -1;
        }

        $expiration -= $this->now();

        return $expiration > 0 ? $expiration : 0.0;
    }

    private function handleSignal(int $signo): void
    {
        foreach ($this->signalWatchers[$signo] as $watcher) {
            if (!isset($this->signalWatchers[$signo][$watcher->id])) {
                continue;
            }

            $this->invokeCallback($watcher);
        }
    }
}
