<?php

declare(strict_types=1);

/*
 * Stand-in for guzzlehttp/guzzle 7 with the same constructor. Only tests that run in a
 * separate process load it: once loaded, discovery would find it in every later test.
 */

namespace GuzzleHttp;

use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ClientInterface
{
    public const MAJOR_VERSION = 7;
}

final class Client implements ClientInterface, Psr18ClientInterface
{
    /** When true, the constructor rejects options, like an incompatible release would. */
    public static bool $rejectOptions = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(public readonly array $config = [])
    {
        if (self::$rejectOptions && $config !== []) {
            throw new \InvalidArgumentException('Unsupported options');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \LogicException('The stub sends no requests.');
    }
}
