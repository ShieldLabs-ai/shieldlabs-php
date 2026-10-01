<?php

declare(strict_types=1);

namespace ShieldLabs\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;

/**
 * Small PSR-18 client on ext-curl. The SDK uses it by default unless the project has
 * Guzzle 7 or Symfony HttpClient, so it works out of the box. It applies a total
 * timeout per request, never follows redirects (so credentials never reach another
 * host) and speaks only HTTP and HTTPS. While `identifications->get()` waits, the SDK
 * uses {@see withTimeout()} to limit a poll to the time left.
 */
final class CurlClient implements ClientInterface
{
    private readonly ResponseFactoryInterface $responseFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param float      $timeout        total time allowed for one request, in seconds
     * @param float|null $connectTimeout time allowed to connect, in seconds (defaults to $timeout)
     *
     * @throws ShieldLabsException when ext-curl is not loaded or a timeout is not positive
     */
    public function __construct(
        private readonly float $timeout = 10.0,
        private readonly ?float $connectTimeout = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (!\extension_loaded('curl')) {
            throw new ShieldLabsException('CurlClient needs the curl PHP extension.');
        }
        if ($timeout <= 0 || ($connectTimeout !== null && $connectTimeout <= 0)) {
            throw new ValidationException('CurlClient timeouts must be greater than zero.');
        }
        $factory = new Psr17Factory();
        $this->responseFactory = $responseFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
    }

    /**
     * Total time allowed for one request, in seconds.
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * A copy of this client with another total time per request. The connect timeout
     * stays as configured and never exceeds the total time.
     *
     * @param float $timeout total time allowed for one request, in seconds
     *
     * @throws ValidationException when the timeout is not greater than zero
     */
    public function withTimeout(float $timeout): self
    {
        if ($timeout === $this->timeout) {
            return $this;
        }

        return new self($timeout, $this->connectTimeout, $this->responseFactory, $this->streamFactory);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        if ($url === '' || $method === '') {
            throw new RequestException('The request has no URI or no method.', $request);
        }
        $headerLines = [];
        foreach ($request->getHeaders() as $name => $values) {
            $line = $name . ': ' . implode(', ', $values);
            if (strpbrk($line, "\r\n\0") !== false) {
                // cURL would send the line as is and split it into several headers.
                throw new RequestException('A request header contains a line break or a NUL byte.', $request);
            }
            $headerLines[] = $line;
        }
        $headerLines[] = 'Expect:';

        $handle = curl_init();
        if ($handle === false) {
            throw new NetworkException('Could not initialize a cURL handle.', $request);
        }

        /** @var list<array{0: string, 1: string}> $responseHeaders */
        $responseHeaders = [];
        $reasonPhrase = '';
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headerLines,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER => false,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($this->connectTimeout ?? $this->timeout, $this->timeout) * 1000),
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_ENCODING => '',
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$reasonPhrase): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new response block (for example after "100 Continue"): start over.
                    $responseHeaders = [];
                    $statusLine = explode(' ', $trimmed, 3);
                    $reasonPhrase = $statusLine[2] ?? '';
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[] = [trim($name), trim($value)];
                }

                return \strlen($line);
            },
        ];
        $body = (string) $request->getBody();
        if ($body !== '') {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $options[\CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($handle, $options);

        $result = curl_exec($handle);
        if (!\is_string($result)) {
            $errno = curl_errno($handle);

            throw new NetworkException(
                \sprintf('cURL error %d: %s', $errno, curl_error($handle)),
                $request,
                $errno === \CURLE_OPERATION_TIMEDOUT,
                $errno,
            );
        }

        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $response = $this->responseFactory
            ->createResponse($status, $reasonPhrase)
            ->withBody($this->streamFactory->createStream($result));
        foreach ($responseHeaders as [$name, $value]) {
            try {
                $response = $response->withAddedHeader($name, $value);
            } catch (\InvalidArgumentException) {
                // Skip a header line that is not valid HTTP.
            }
        }

        return $response;
    }
}
