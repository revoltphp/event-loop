<?php

namespace Revolt;

/**
 * Returns the current time relative to an arbitrary point in time.
 *
 * @return float Time in seconds.
 */
function now(): float
{
    return (float) \hrtime(true) / 1_000_000_000;
}
