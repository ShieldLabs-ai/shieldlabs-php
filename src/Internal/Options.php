<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use ShieldLabs\Exception\ValidationException;

/**
 * Validation helpers for option arrays.
 *
 * @internal
 */
final class Options
{
    private function __construct() {}

    /**
     * @param array<mixed> $options
     * @param list<string> $allowed
     *
     * @throws ValidationException when an option name is not in $allowed
     */
    public static function assertKnown(array $options, array $allowed, string $context): void
    {
        foreach (array_keys($options) as $key) {
            if (!\is_string($key) || !\in_array($key, $allowed, true)) {
                throw new ValidationException(\sprintf(
                    'Unknown option "%s" for %s. Allowed options: %s.',
                    (string) $key,
                    $context,
                    implode(', ', $allowed),
                ));
            }
        }
    }

    /**
     * Raw value of an option, or null.
     *
     * @param array<mixed> $options
     */
    public static function get(array $options, string $key): mixed
    {
        return $options[$key] ?? null;
    }

    /**
     * A number of seconds (int or float). Null or a missing key gives the default.
     *
     * @param array<mixed> $options
     *
     * @throws ValidationException
     */
    public static function seconds(array $options, string $key, float $default, bool $allowZero): float
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)) {
            throw new ValidationException(\sprintf('Option "%s" must be a number of seconds.', $key));
        }
        $value = (float) $value;
        if ($value < 0 || (!$allowZero && $value == 0.0)) {
            throw new ValidationException(\sprintf(
                'Option "%s" must be %s.',
                $key,
                $allowZero ? 'zero or greater' : 'greater than zero',
            ));
        }

        return $value;
    }

    /**
     * An integer within [$min, $max]. Null or a missing key gives the default.
     *
     * @param array<mixed> $options
     *
     * @throws ValidationException
     */
    public static function integer(array $options, string $key, int $default, int $min, ?int $max = null): int
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            throw new ValidationException(\sprintf(
                'Option "%s" must be an integer %s.',
                $key,
                $max === null ? \sprintf('greater than or equal to %d', $min) : \sprintf('from %d to %d', $min, $max),
            ));
        }

        return $value;
    }

    /**
     * @param array<mixed> $options
     *
     * @throws ValidationException
     */
    public static function boolean(array $options, string $key, bool $default): bool
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_bool($value)) {
            throw new ValidationException(\sprintf('Option "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * A string option, falling back to an environment variable when the option is
     * missing, null or false (what getenv() returns for an unset variable). An
     * explicit string, even an empty one, is returned as given.
     *
     * @param array<mixed> $options
     *
     * @throws ValidationException
     */
    public static function stringOrEnv(array $options, string $key, string $envName): ?string
    {
        $value = $options[$key] ?? null;
        if ($value === null || $value === false) {
            return Env::get($envName);
        }

        return self::string($options, $key);
    }

    /**
     * A string option, or null when it is missing or null.
     *
     * @param array<mixed> $options
     *
     * @throws ValidationException
     */
    public static function string(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw new ValidationException(\sprintf('Option "%s" must be a string.', $key));
        }

        return $value;
    }
}
