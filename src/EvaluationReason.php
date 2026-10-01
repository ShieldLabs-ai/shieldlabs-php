<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * Why {@see Risk::evaluate()} refused an identification.
 */
enum EvaluationReason: string
{
    /** No identification was found for the request ID (unverified, never "clean"). */
    case Missing = 'missing';
    /** The request ID was already used for a protected action. */
    case Replayed = 'replayed';
    /** The identification is older than the freshness window (max_age). */
    case Stale = 'stale';
    /** The score is the rate-limit marker (above 100). */
    case RateLimited = 'rate_limited';
    /** The device ID is the all-zero UUID: no usable device signals. */
    case NoDeviceSignals = 'no_device_signals';
    /** One of the blocking detection flags is set. */
    case BlockedFlag = 'blocked_flag';
    /** The risk band is one of the blocking bands. */
    case BlockedBand = 'blocked_band';
}
