<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

use ShieldLabs\Internal\Clock;

/**
 * Virtual clock: time only moves when the code under test sleeps.
 */
final class FakeClock implements Clock
{
    /** @var list<float> */
    public array $sleeps = [];

    /**
     * @param float $wakesEarlyBy seconds by which a sleep longer than 1 ms ends early, the
     *                            way a real sleep can end a little before the time asked for
     */
    public function __construct(private float $time = 1000.0, private readonly float $wakesEarlyBy = 0.0) {}

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds > 0.001 ? $seconds - $this->wakesEarlyBy : $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }

    public function elapsed(float $since = 1000.0): float
    {
        return $this->time - $since;
    }
}
