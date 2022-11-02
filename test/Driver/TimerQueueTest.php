<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\Internal\TimerQueue;

class TimerQueueTest extends TestCase
{
    public function testHeapOrder(): void
    {
        $values = [
            29022197.0,
            29026651.0,
            29026649.0,
            29032037.0,
            29031955.0,
            29032037.0,
            29031870.0,
            29032136.0,
            29032075.0,
            29032144.0,
            29032160.0,
            29032101.0,
            29032130.0,
            29032091.0,
            29032107.0,
            29032181.0,
            29032137.0,
            29032142.0,
            29032142.0,
            29032146.0,
            29032158.0,
            29032166.0,
            29032177.0,
            29032181.0,
            29032180.0,
            29032184.0,
            29032193.0,
            29032122.0,
        ];
        $indexToRemove = 16;
        $queue = new TimerQueue();
        $id = 'a';
        $callbacks = [];
        foreach ($values as $value) {
            $callback = new TimerCallback($id++, $value, static function () {
            }, $value);
            $callbacks[] = $callback;
        }

        $toRemove = $callbacks[$indexToRemove];
        foreach ($callbacks as $callback) {
            $queue->insert($callback);
        }
        $queue->remove($toRemove);

        \array_splice($values, $indexToRemove, 1);
        \sort($values);
        $output = [];
        while (($extracted = $queue->extract(\PHP_INT_MAX)) !== null) {
            $output[] = $extracted->expiration;
        }

        self::assertSame($values, $output);
    }
}
