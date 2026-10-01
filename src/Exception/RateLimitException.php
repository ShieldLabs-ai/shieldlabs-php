<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * HTTP 429: too many requests.
 *
 * The History API allows about 15 requests per second per domain and recovers in the
 * next second, so the History client retries it. The Management API allows about 15
 * requests per minute per client IP and then blocks that IP for 10 minutes, so the
 * Management client never retries it.
 */
final class RateLimitException extends ApiException
{
    /**
     * @param array<string, list<string>> $headers
     * @param float|null                  $retryAfter seconds from the Retry-After header, when present
     */
    public function __construct(
        string $message,
        int $statusCode,
        mixed $body = null,
        string $rawBody = '',
        array $headers = [],
        ?string $errorMessage = null,
        private readonly ?float $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $body, $rawBody, $headers, $errorMessage, $previous);
    }

    /**
     * Seconds to wait before the next request, from the Retry-After header, or null
     * when the server did not send one.
     */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
