<?php

declare(strict_types=1);

/*
 * Stand-in for the HttpClient factory of symfony/http-client with the same signature.
 * Only tests that run in a separate process load it.
 */

namespace Symfony\Component\HttpClient;

final class HttpClient
{
    /** When true, create() returns a client without withOptions(), like releases before 5.3. */
    public static bool $withoutWithOptions = false;

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public static function create(array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50): StubHttpClient|OlderStubHttpClient
    {
        return self::$withoutWithOptions ? new OlderStubHttpClient($defaultOptions) : new StubHttpClient($defaultOptions);
    }
}

final class StubHttpClient
{
    /**
     * @param array<string, mixed> $options
     * @param self|null            $derivedFrom the client withOptions() was called on
     */
    public function __construct(public readonly array $options, public readonly ?self $derivedFrom = null) {}

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        return new self(array_replace($this->options, $options), $this);
    }
}

final class OlderStubHttpClient
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(public readonly array $options) {}
}
