<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

/**
 * @internal
 */
final class Time
{
    private function __construct() {}

    /**
     * Builds a UTC timestamp from validated parts. Fractions beyond microseconds are
     * truncated (never rounded). Returns null for impossible dates such as 2026-02-30.
     *
     * @param string $date     YYYY-MM-DD
     * @param string $time     HH:MM:SS
     * @param string $fraction fractional-second digits, possibly empty
     * @param string $offset   +HH:MM or -HH:MM (also +HHMM)
     */
    public static function build(string $date, string $time, string $fraction, string $offset): ?\DateTimeImmutable
    {
        $micro = str_pad(substr($fraction === '' ? '0' : $fraction, 0, 6), 6, '0');
        if (preg_match('/^([+-]\d{2}):?(\d{2})$/D', $offset, $parts) !== 1) {
            return null;
        }
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u P', \sprintf('%s %s.%s %s:%s', $date, $time, $micro, $parts[1], $parts[2]));
        if ($value === false) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (\is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $value->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * RFC 3339 in UTC with millisecond precision (truncated), for example
     * 2026-09-30T12:34:56.123Z.
     */
    public static function format(?\DateTimeImmutable $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $utc = $value->setTimezone(new \DateTimeZone('UTC'));

        return \sprintf('%04d-%s', (int) $utc->format('Y'), $utc->format('m-d\TH:i:s.v\Z'));
    }

    /**
     * Unix time in seconds (with microseconds) of a date.
     */
    public static function unix(\DateTimeInterface $value): float
    {
        return (float) $value->format('U.u');
    }
}
