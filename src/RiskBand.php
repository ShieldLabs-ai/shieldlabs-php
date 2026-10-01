<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * Client-side label for a Risk Score: trusted 0-29, suspicious 30-59, dangerous
 * 60-100. A score above 100 (999) is the rate-limit marker, never a score, and maps
 * to {@see RiskBand::RateLimited}.
 */
enum RiskBand: string
{
    case Trusted = 'trusted';
    case Suspicious = 'suspicious';
    case Dangerous = 'dangerous';
    case RateLimited = 'rate_limited';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score > 100 => self::RateLimited,
            $score >= 60 => self::Dangerous,
            $score >= 30 => self::Suspicious,
            default => self::Trusted,
        };
    }
}
