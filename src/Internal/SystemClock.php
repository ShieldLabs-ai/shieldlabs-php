<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

/**
 * @internal
 */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $whole = (int) floor($seconds);
        $micro = (int) round(($seconds - $whole) * 1_000_000);
        if ($whole > 0) {
            sleep($whole);
        }
        if ($micro > 0) {
            usleep($micro);
        }
    }
}
