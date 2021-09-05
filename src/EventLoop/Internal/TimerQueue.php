<?php

namespace Revolt\EventLoop\Internal;

/**
 * Uses a binary tree stored in an array to implement a heap.
 *
 * @internal
 */
final class TimerQueue
{
    /** @var TimerWatcher[] */
    private array $watchers = [];

    /** @var int[] */
    private array $pointers = [];

    /**
     * Inserts the watcher into the queue.
     *
     * Time complexity: O(log(n)).
     */
    public function insert(TimerWatcher $watcher): void
    {
        \assert(!isset($this->pointers[$watcher->id]));

        $node = \count($this->watchers);
        $this->watchers[$node] = $watcher;
        $this->pointers[$watcher->id] = $node;

        $this->heapifyUp($node);
    }

    /**
     * Removes the given watcher from the queue.
     *
     * Time complexity: O(log(n)).
     */
    public function remove(TimerWatcher $watcher): void
    {
        $id = $watcher->id;

        if (!isset($this->pointers[$id])) {
            return;
        }

        $this->removeAndRebuild($this->pointers[$id]);
    }

    /**
     * Deletes and returns the watcher on top of the heap if it has expired, otherwise null is returned.
     *
     * Time complexity: O(log(n)).
     *
     * @param float $now Current loop time.
     *
     * @return TimerWatcher|null Expired watcher at the top of the heap or null if the watcher has not expired.
     */
    public function extract(float $now): ?TimerWatcher
    {
        if (!$this->watchers) {
            return null;
        }

        $watcher = $this->watchers[0];
        if ($watcher->expiration > $now) {
            return null;
        }

        $this->removeAndRebuild(0);

        return $watcher;
    }

    /**
     * Returns the expiration time value at the top of the heap.
     *
     * Time complexity: O(1).
     *
     * @return float|null Expiration time of the watcher at the top of the heap or null if the heap is empty.
     */
    public function peek(): ?float
    {
        return isset($this->watchers[0]) ? $this->watchers[0]->expiration : null;
    }

    /**
     * @param int $node Rebuild the data array from the given node upward.
     */
    private function heapifyUp(int $node): void
    {
        $entry = $this->watchers[$node];
        while ($node !== 0 && $entry->expiration < $this->watchers[$parent = ($node - 1) >> 1]->expiration) {
            $this->swap($node, $parent);
            $node = $parent;
        }
    }

    /**
     * @param int $node Rebuild the data array from the given node downward.
     */
    private function heapifyDown(int $node): void
    {
        $length = \count($this->watchers);
        while (($child = ($node << 1) + 1) < $length) {
            if ($this->watchers[$child]->expiration < $this->watchers[$node]->expiration
                && ($child + 1 >= $length || $this->watchers[$child]->expiration < $this->watchers[$child + 1]->expiration)
            ) {
                // Left child is less than parent and right child.
                $swap = $child;
            } elseif ($child + 1 < $length && $this->watchers[$child + 1]->expiration < $this->watchers[$node]->expiration) {
                // Right child is less than parent and left child.
                $swap = $child + 1;
            } else { // Left and right child are greater than parent.
                break;
            }

            $this->swap($node, $swap);
            $node = $swap;
        }
    }

    private function swap(int $left, int $right): void
    {
        $temp = $this->watchers[$left];

        $this->watchers[$left] = $this->watchers[$right];
        $this->pointers[$this->watchers[$right]->id] = $left;

        $this->watchers[$right] = $temp;
        $this->pointers[$temp->id] = $right;
    }

    /**
     * @param int $node Remove the given node and then rebuild the data array.
     */
    private function removeAndRebuild(int $node): void
    {
        $length = \count($this->watchers) - 1;
        $id = $this->watchers[$node]->id;
        $left = $this->watchers[$node] = $this->watchers[$length];
        $this->pointers[$left->id] = $node;
        unset($this->watchers[$length], $this->pointers[$id]);

        if ($node < $length) { // don't need to do anything if we removed the last element
            $parent = ($node - 1) >> 1;
            if ($parent >= 0 && $this->watchers[$node]->expiration < $this->watchers[$parent]->expiration) {
                $this->heapifyUp($node);
            } else {
                $this->heapifyDown($node);
            }
        }
    }
}
