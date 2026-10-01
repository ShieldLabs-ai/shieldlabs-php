<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use Psr\Http\Message\ResponseInterface;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\AuthenticationException;
use ShieldLabs\Exception\BadRequestException;
use ShieldLabs\Exception\NotFoundException;
use ShieldLabs\Exception\QuotaExceededException;
use ShieldLabs\Exception\RateLimitException;
use ShieldLabs\Exception\ServerException;

/**
 * Builds typed exceptions from error responses. Error bodies are not uniform
 * (empty, a bare JSON string, an object with an "error" key, plain text or an HTML
 * proxy page, sometimes JSON sent as text/plain), so everything here is defensive
 * and never throws.
 *
 * @internal
 */
final class ResponseErrors
{
    private const MAX_MESSAGE_LENGTH = 500;

    private function __construct() {}

    /**
     * @param string|null $rawBody the body when it was already read (a streamed body can be read only once)
     */
    public static function fromResponse(ResponseInterface $response, ?string $rawBody = null): ApiException
    {
        $status = $response->getStatusCode();
        $raw = $rawBody ?? self::body($response);
        $headers = self::headers($response);
        [$body, $serverMessage] = self::parse($raw);
        $message = \sprintf('ShieldLabs API error (HTTP %d)', $status)
            . ($serverMessage !== null ? ': ' . $serverMessage : '');

        return match (true) {
            $status === 400 => new BadRequestException($message, $status, $body, $raw, $headers, $serverMessage),
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $body, $raw, $headers, $serverMessage),
            $status === 402 => new QuotaExceededException($message, $status, $body, $raw, $headers, $serverMessage),
            $status === 404 => new NotFoundException($message, $status, $body, $raw, $headers, $serverMessage),
            $status === 429 => new RateLimitException(
                $message,
                $status,
                $body,
                $raw,
                $headers,
                $serverMessage,
                self::retryAfter($headers['retry-after'][0] ?? null),
            ),
            $status >= 500 && $status <= 599 => new ServerException($message, $status, $body, $raw, $headers, $serverMessage),
            default => new ApiException($message, $status, $body, $raw, $headers, $serverMessage),
        };
    }

    /**
     * Seconds from a Retry-After header (delta-seconds or an HTTP date), or null.
     */
    public static function retryAfter(?string $value, ?int $now = null): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d+(?:\.\d+)?$/D', $value) === 1) {
            return (float) $value;
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \\G\\M\\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return (float) max(0, $date->getTimestamp() - ($now ?? time()));
    }

    public static function body(ResponseInterface $response): string
    {
        try {
            return (string) $response->getBody();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $key = strtolower((string) $name);
            foreach ($values as $value) {
                $headers[$key][] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array{0: mixed, 1: string|null} decoded body and the server's error text
     */
    private static function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [$raw, null];
        }
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $text = trim($raw);
            $isShortText = !str_contains($text, '<') && \strlen($text) <= self::MAX_MESSAGE_LENGTH;

            return [$raw, $isShortText ? $text : null];
        }
        if (\is_string($decoded)) {
            return [$decoded, $decoded !== '' ? self::truncate($decoded) : null];
        }
        if (\is_array($decoded)) {
            foreach (['error', 'message'] as $key) {
                if (isset($decoded[$key]) && \is_string($decoded[$key]) && $decoded[$key] !== '') {
                    return [$decoded, self::truncate($decoded[$key])];
                }
            }
        }

        return [$decoded, null];
    }

    private static function truncate(string $text): string
    {
        return \strlen($text) > self::MAX_MESSAGE_LENGTH ? substr($text, 0, self::MAX_MESSAGE_LENGTH) . '...' : $text;
    }
}
