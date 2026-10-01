<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use ShieldLabs\Exception\ApiConnectionException;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ApiTimeoutException;
use ShieldLabs\Exception\RateLimitException;
use ShieldLabs\Exception\ServerException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Http\CurlClient;
use ShieldLabs\Http\NetworkException;

/**
 * Sends GET requests with the retry policy shared by the ShieldLabs server SDKs:
 * retry connection errors, timeouts, 5xx and (when enabled) 429; exponential
 * backoff with jitter (base 0.5 s, factor 2, cap 8 s); Retry-After honoured up to
 * 10 s; a 429 without Retry-After waits at least 1 s; never retry other 4xx statuses.
 *
 * @internal
 */
final class Transport
{
    public const BACKOFF_BASE = 0.5;
    public const BACKOFF_FACTOR = 2.0;
    public const BACKOFF_CAP = 8.0;
    public const RETRY_AFTER_CAP = 10.0;

    /**
     * Shortest wait after a 429 without Retry-After, and after any 429 while
     * identifications->get() waits: the History API counts requests per second, so
     * an earlier request would land in the same window.
     */
    public const RATE_LIMIT_MIN_WAIT = 1.0;

    /**
     * @param array<string, string> $headers sent with every request
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $baseUrl,
        private readonly array $headers,
        private readonly int $maxRetries,
        private readonly bool $retryRateLimited,
        private readonly Clock $clock,
    ) {}

    /**
     * GET a path under the base URL and return the decoded JSON body.
     *
     * @param array<string, int|string> $query
     * @param int|null                  $maxRetries     retries for this call instead of the configured number
     * @param float|null                $attemptTimeout seconds each attempt may take at most, where the HTTP client allows it
     *
     * @throws ShieldLabsException
     */
    public function getJson(string $path, array $query = [], ?int $maxRetries = null, ?float $attemptTimeout = null): mixed
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }
        $client = $this->clientFor($attemptTimeout);
        $maxRetries ??= $this->maxRetries;

        for ($attempt = 0; ; ++$attempt) {
            try {
                [$response, $raw] = $this->send($client, $url);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return $this->decode($response, $raw);
                }
                $error = ResponseErrors::fromResponse($response, $raw);
            } catch (ApiConnectionException|ApiTimeoutException $exception) {
                $error = $exception;
            }

            if ($attempt >= $maxRetries || !$this->isRetryable($error)) {
                throw $error;
            }
            $this->clock->sleep($this->retryDelay($attempt, $error));
        }
    }

    /**
     * The HTTP client for one call. With an attempt timeout shorter than the client's
     * own, the built-in {@see CurlClient} and the clients the SDK created itself are
     * replaced by a copy limited to it. Any other client passed as `http_client`
     * keeps its own timeout, because PSR-18 has no way to change it per request.
     */
    private function clientFor(?float $attemptTimeout): ClientInterface
    {
        $client = $this->client;
        if (
            $attemptTimeout !== null
            && ($client instanceof CurlClient || $client instanceof ConfiguredClient)
            && $attemptTimeout < $client->getTimeout()
        ) {
            return $client->withTimeout($attemptTimeout);
        }

        return $client;
    }

    /**
     * Delay before retry number $attempt + 1.
     */
    public function retryDelay(int $attempt, ShieldLabsException $error): float
    {
        $retryAfter = null;
        if ($error instanceof RateLimitException) {
            $retryAfter = $error->getRetryAfter();
        } elseif ($error instanceof ServerException) {
            $retryAfter = ResponseErrors::retryAfter($error->getHeader('retry-after'));
        }
        if ($retryAfter !== null) {
            return min(max($retryAfter, 0.0), self::RETRY_AFTER_CAP);
        }
        $backoff = min(self::BACKOFF_CAP, self::BACKOFF_BASE * self::BACKOFF_FACTOR ** $attempt);
        $delay = $backoff * (0.5 + 0.5 * (random_int(0, 1_000_000) / 1_000_000));

        return $error instanceof RateLimitException ? max($delay, self::RATE_LIMIT_MIN_WAIT) : $delay;
    }

    private function isRetryable(ShieldLabsException $error): bool
    {
        return match (true) {
            $error instanceof ApiTimeoutException, $error instanceof ServerException => true,
            $error instanceof ApiConnectionException => !$error->getPrevious() instanceof RequestExceptionInterface,
            $error instanceof RateLimitException => $this->retryRateLimited,
            default => false,
        };
    }

    /**
     * Sends one attempt and reads the whole response body.
     *
     * @return array{0: ResponseInterface, 1: string} the response and its body
     *
     * @throws ApiConnectionException
     * @throws ApiTimeoutException
     * @throws ValidationException when the PSR-7 layer refuses the URL or a header
     */
    private function send(ClientInterface $client, string $url): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $url);
            foreach ($this->headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        } catch (\InvalidArgumentException) {
            // Some PSR-7 implementations quote the refused header value, the API key
            // included, so their message is neither repeated nor chained.
            throw new ValidationException('The request could not be built: the URL or a header value contains characters that HTTP does not allow.');
        }

        try {
            $response = $client->sendRequest($request);
        } catch (NetworkExceptionInterface $exception) {
            if (self::isTimeout($exception)) {
                throw new ApiTimeoutException('The ShieldLabs API did not answer in time: ' . $exception->getMessage(), 0, $exception);
            }

            throw new ApiConnectionException('Could not reach the ShieldLabs API: ' . $exception->getMessage(), 0, $exception);
        } catch (RequestExceptionInterface $exception) {
            throw new ApiConnectionException('The HTTP client could not send the request: ' . $exception->getMessage(), 0, $exception);
        } catch (ClientExceptionInterface $exception) {
            if (self::isTimeout($exception)) {
                throw new ApiTimeoutException('The ShieldLabs API did not answer in time: ' . $exception->getMessage(), 0, $exception);
            }

            throw new ApiConnectionException('Could not reach the ShieldLabs API: ' . $exception->getMessage(), 0, $exception);
        }

        // Some clients (Symfony HttpClient, for one) return once the headers arrive and
        // stream the body afterwards, so a timeout or a dropped connection can surface
        // only while the body is read. Read it here and treat such a failure like any
        // other transport error, retries included.
        try {
            return [$response, (string) $response->getBody()];
        } catch (\Exception $exception) {
            if (self::isTimeoutMessage($exception->getMessage())) {
                throw new ApiTimeoutException('The ShieldLabs API did not answer in time: ' . $exception->getMessage(), 0, $exception);
            }

            throw new ApiConnectionException('The response of the ShieldLabs API could not be read: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private static function isTimeout(ClientExceptionInterface $exception): bool
    {
        if ($exception instanceof NetworkException) {
            return $exception->isTimeout();
        }

        return self::isTimeoutMessage($exception->getMessage());
    }

    private static function isTimeoutMessage(string $message): bool
    {
        return preg_match('/timed? ?out|timeout|max duration|curl error 28\b/i', $message) === 1;
    }

    /**
     * @throws ApiException when a success response is not JSON
     */
    private function decode(ResponseInterface $response, string $raw): mixed
    {
        try {
            return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException(
                'The ShieldLabs API returned a response body that is not valid JSON.',
                $response->getStatusCode(),
                $raw,
                $raw,
                ResponseErrors::headers($response),
                null,
                $exception,
            );
        }
    }
}
