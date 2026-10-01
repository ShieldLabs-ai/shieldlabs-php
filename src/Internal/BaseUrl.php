<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use ShieldLabs\Exception\ValidationException;

/**
 * @internal
 */
final class BaseUrl
{
    /** A host name or IPv4 address, or an IPv6 address in brackets. */
    private const HOST_PATTERN = '/^(?:[A-Za-z0-9._-]+|\[[0-9A-Fa-f:.]+\])$/D';

    private function __construct() {}

    /**
     * Normalizes a base URL: an https origin with an optional path prefix, no trailing
     * slash, and without the given trailing path segment (for example "/api" for the
     * History API, whose request paths already start with "/api"). Plain http is
     * accepted for loopback hosts (localhost, *.localhost, 127.0.0.0/8 and [::1]), and
     * for other hosts only with $allowInsecureHttp, because the key would travel
     * unencrypted.
     *
     * @throws ValidationException
     */
    public static function normalize(string $url, string $optionName, string $stripSuffix, bool $allowInsecureHttp = false): string
    {
        $url = trim($url);
        // parse_url() would silently turn control characters into "_", so check first.
        $parts = preg_match('/^[\x21-\x7E]+$/D', $url) === 1 ? parse_url($url) : false;
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || preg_match(self::HOST_PATTERN, $parts['host']) !== 1
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new ValidationException(\sprintf(
                'Option "%s" must be an http(s) URL such as https://example.com (no query string or credentials).',
                $optionName,
            ));
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'http' && !self::isLoopback($parts['host'])) {
            if (!$allowInsecureHttp) {
                throw new ValidationException(\sprintf(
                    'Option "%s" must be an https URL: over plain http the key would travel unencrypted. Plain http is accepted '
                    . 'for localhost, 127.0.0.1 and [::1]; for a test server on another host, pass "allow_insecure_http" => true.',
                    $optionName,
                ));
            }
            Warning::once(\sprintf(
                'option "%s" uses plain http, so the key travels unencrypted. Use https outside local testing.',
                $optionName,
            ));
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($stripSuffix !== '' && str_ends_with($path, $stripSuffix)) {
            $path = rtrim(substr($path, 0, -\strlen($stripSuffix)), '/');
        }

        return $scheme . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $path;
    }

    /**
     * True for localhost and its subdomains, 127.0.0.0/8 and [::1].
     */
    public static function isLoopback(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $address = substr($host, 1, -1);

            return filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false
                && inet_pton($address) === inet_pton('::1');
        }

        return false;
    }
}
