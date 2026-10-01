<?php

declare(strict_types=1);

namespace ShieldLabs\Http;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The request cannot be sent as given (PSR-18). Raised by {@see CurlClient}.
 */
final class RequestException extends \InvalidArgumentException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
