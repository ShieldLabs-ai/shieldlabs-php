<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

/**
 * Monotonic time source and sleep function used by retries and polling.
 *
 * @internal
 */
interface Clock
{
    /**
     * Monotonic time in seconds (only differences are meaningful).
     */
    public function now(): float;

    public function sleep(float $seconds): void;
}
