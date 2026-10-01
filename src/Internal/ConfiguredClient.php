<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that the SDK created with its timeout (a discovered Guzzle or
 * Symfony HttpClient) and can create again with a shorter one, so that a poll of
 * `identifications->get()` never runs much longer than the time left.
 *
 * @internal
 */
final class ConfiguredClient implements ClientInterface
{
    /** The client configured with {@see getTimeout()}. */
    public readonly ClientInterface $client;

    /**
     * @param \Closure(float): ClientInterface $create  builds the client for a total time per request, in seconds
     * @param float                            $timeout total time allowed for one request, in seconds
     */
    public function __construct(
        private readonly \Closure $create,
        private readonly float $timeout,
    ) {
        $this->client = ($this->create)($timeout);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * The same kind of client with another total time per request.
     */
    public function withTimeout(float $timeout): self
    {
        if ($timeout === $this->timeout) {
            return $this;
        }

        return new self($this->create, $timeout);
    }
}
