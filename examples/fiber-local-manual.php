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
        $this->transactionId = new FiberLocal(fn () => throw new \Exception('Unknown transaction ID'));
    }

    public function setTransactionId(int $transactionId): void
    {
        $this->transactionId->set($transactionId);
    }

    public function unsetTransactionId(): void
    {
        $this->transactionId->unset();
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
    $continuation = $suspension->getContinuation();
    EventLoop::delay(1, static fn () => $continuation->resume());
    $suspension->suspend();

    $logger->log('Done.');

    $logger->unsetTransactionId();

    try {
        $logger->log('Outside transaction');
    } catch (\Exception) {
        echo 'Caught exception, because we\'re outside a transaction' . PHP_EOL;
    }

    $logger->setTransactionId(3);

    $logger->log('Initializing...');

    $suspension = EventLoop::getSuspension();
    $continuation = $suspension->getContinuation();
    EventLoop::delay(1, static fn () => $continuation->resume());
    $suspension->suspend();

    $logger->log('Done.');

    $logger->unsetTransactionId();
});

$logger->log('Initializing...');

$suspension = EventLoop::getSuspension();
$continuation = $suspension->getContinuation();
EventLoop::delay(3, static fn () => $continuation->resume());
$suspension->suspend();

$logger->log('Done.');
