<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * The API answered with a non-success HTTP status.
 *
 * Specific statuses have their own subclasses: {@see BadRequestException} (400),
 * {@see AuthenticationException} (401, 403), {@see QuotaExceededException} (402),
 * {@see NotFoundException} (404), {@see RateLimitException} (429) and
 * {@see ServerException} (5xx). Other statuses use this class directly.
 */
class ApiException extends ShieldLabsException
{
    /**
     * @param int                         $statusCode   HTTP status code of the response
     * @param mixed                       $body         decoded JSON body, or the raw text when the body is not JSON
     * @param string                      $rawBody      response body exactly as received
     * @param array<string, list<string>> $headers      response headers, names in lowercase
     * @param string|null                 $errorMessage message the server put in the body, when there is one
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly mixed $body = null,
        private readonly string $rawBody = '',
        private readonly array $headers = [],
        private readonly ?string $errorMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The response body: decoded JSON when it parses (an array, a string or null),
     * otherwise the raw text. Bodies differ per endpoint and status (empty, a bare
     * JSON string, an object with an "error" key, plain text or an HTML proxy page).
     */
    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * The error text the server sent (for example "too many requests"), or null.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, list<string>> response headers with lowercase names
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * First value of a response header (case-insensitive name), or null.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
