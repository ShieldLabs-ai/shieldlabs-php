<?php

declare(strict_types=1);

namespace ShieldLabs\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Network failure raised by {@see CurlClient} (PSR-18). The SDK converts it into
 * {@see \ShieldLabs\Exception\ApiConnectionException} or
 * {@see \ShieldLabs\Exception\ApiTimeoutException}.
 */
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly bool $timeout = false,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * True when the transfer was stopped by the client timeout.
     */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
