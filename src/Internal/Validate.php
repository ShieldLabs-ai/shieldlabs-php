<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use ShieldLabs\Exception\ValidationException;
use ShieldLabs\LookupType;

/**
 * Client-side checks, applied before anything is sent: History lookups (an unknown
 * type would return unfiltered rows, and a malformed UUID or IP address an HTTP 500)
 * and the credentials that travel in HTTP headers.
 *
 * @internal
 */
final class Validate
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
    private const IPV4_PATTERN = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/D';

    /** Escapes that canonical path escaping leaves as plain characters. */
    private const PLAIN_IN_PATH = [
        '%24' => '$',
        '%26' => '&',
        '%2B' => '+',
        '%2C' => ',',
        '%3A' => ':',
        '%3B' => ';',
        '%3D' => '=',
        '%40' => '@',
    ];

    private function __construct() {}

    /**
     * @throws ValidationException
     */
    public static function lookupType(LookupType|string $type): LookupType
    {
        if ($type instanceof LookupType) {
            return $type;
        }
        $lookup = LookupType::tryFrom($type);
        if ($lookup === null) {
            throw new ValidationException(\sprintf(
                'Unknown lookup type "%s". Use one of: %s.',
                $type,
                implode(', ', array_map(static fn(LookupType $case): string => $case->value, LookupType::cases())),
            ));
        }

        return $lookup;
    }

    /**
     * Returns the value exactly as it must be sent (UUIDs in lowercase).
     *
     * @throws ValidationException
     */
    public static function lookupValue(LookupType $type, string $value): string
    {
        return match ($type) {
            LookupType::Ip => self::ipv4($value),
            LookupType::UserHid => self::userHid($value),
            default => self::uuid($value, $type->value),
        };
    }

    /**
     * @throws ValidationException
     */
    public static function uuid(string $value, string $name): string
    {
        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new ValidationException(\sprintf(
                'The %s must be a UUID in the 8-4-4-4-12 hexadecimal form.',
                $name,
            ));
        }

        return strtolower($value);
    }

    /**
     * @throws ValidationException
     */
    public static function ipv4(string $value): string
    {
        if (preg_match(self::IPV4_PATTERN, $value) !== 1 || filter_var($value, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            throw new ValidationException('The ip lookup takes a dotted IPv4 address (IPv6 addresses are not searchable).');
        }

        return $value;
    }

    /**
     * Checks that a key, secret or domain can be sent as an HTTP header value: visible
     * ASCII characters only, so no line break, NUL, space or control character. The
     * check runs before any request because the errors of HTTP libraries can quote the
     * rejected header; the message never includes the value.
     *
     * @param string $name how the message refers to the value, for example "The API key"
     *
     * @throws ValidationException
     */
    public static function headerValue(string $value, string $name): string
    {
        if (preg_match('/^[\x21-\x7E]+$/D', $value) !== 1) {
            throw new ValidationException(\sprintf(
                '%s contains characters that cannot be sent in an HTTP header (only visible ASCII characters are allowed).',
                $name,
            ));
        }

        return $value;
    }

    /**
     * A User HID travels as one URL path segment. The History API cannot search a value
     * that contains "/" (neither sent as is nor escaped), and HTTP clients remove the
     * segments "." and ".." from a URL, so such values are refused here.
     *
     * @throws ValidationException
     */
    public static function userHid(string $value): string
    {
        if ($value === '') {
            throw new ValidationException('The user_hid lookup value must be a non-empty string.');
        }
        if ($value === '.' || $value === '..') {
            throw new ValidationException('The user_hid lookup value cannot be "." or "..": HTTP clients drop such URL path segments, so the History API cannot search them.');
        }
        if (str_contains($value, '/')) {
            throw new ValidationException('The user_hid lookup value cannot contain "/": the History API cannot search a value with a slash. User HIDs from UserHid::fromUserId() are 64 hex characters and always work.');
        }

        return $value;
    }

    /**
     * Escapes a validated lookup value as one URL path segment in canonical form:
     * A-Z, a-z, 0-9, "-", ".", "_", "~" and $ & + , : ; = @ stay as they are, and every
     * other byte becomes %XX with uppercase hex digits. The History API decodes the
     * value only when the path is escaped exactly this way; otherwise it compares the
     * escaped text, which matches no row.
     */
    public static function pathSegment(string $value): string
    {
        return strtr(rawurlencode($value), self::PLAIN_IN_PATH);
    }
}
