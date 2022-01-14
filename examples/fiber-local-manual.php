<?php

use Revolt\EventLoop;
use Revolt\EventLoop\FiberLocal;

require __DIR__ . '/../vendor/autoload.php';

/**
 * This logger uses {@see FiberLocal} to automatically log a transaction identifier bound to the current fiber.
 *
 * This might be used to log the current URL, authenticated user, or request identifier in an HTTP server.
 */
final class Logger
{
    private FiberLocal $transactionId;

    public function __construct()
    {
        $this->transactionId = new FiberLocal(fn () => null);
    }

    public function setTransactionId(int $transactionId): void
    {
        $this->transactionId->set($transactionId);
    }

    public function log(string $message): void
    {
        echo $this->transactionId->get() . ': ' . $message . PHP_EOL;
    }
}

$logger = new Logger();
$logger->setTransactionId(1);

EventLoop::delay(1, static function () use ($logger) {
    $logger->setTransactionId(2);

    $logger->log('Initializing...');

    $suspension = EventLoop::getSuspension();
    EventLoop::delay(1, static fn () => $suspension->resume());
    $suspension->suspend();

    $logger->log('Done.');
});

$logger->log('Initializing...');

$suspension = EventLoop::getSuspension();
EventLoop::delay(3, static fn () => $suspension->resume());
$suspension->suspend();

$logger->log('Done.');
