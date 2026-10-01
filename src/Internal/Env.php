<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

/**
 * @internal
 */
final class Env
{
    private function __construct() {}

    /**
     * Value of an environment variable, or null when it is unset or empty.
     */
    public static function get(string $name): ?string
    {
        $value = getenv($name);
        if (\is_string($value) && $value !== '') {
            return $value;
        }
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
