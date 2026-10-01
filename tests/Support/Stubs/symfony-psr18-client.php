<?php

declare(strict_types=1);

/*
 * Stand-in for the PSR-18 adapter of symfony/http-client with the same constructor.
 * Only tests that run in a separate process load it.
 */

namespace Symfony\Component\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr18Client implements ClientInterface
{
    public function __construct(
        public readonly ?object $client = null,
        public readonly ?ResponseFactoryInterface $responseFactory = null,
        public readonly ?StreamFactoryInterface $streamFactory = null,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \LogicException('The stub sends no requests.');
    }
}
