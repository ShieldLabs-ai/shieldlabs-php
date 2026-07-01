<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * ShieldLabs server SDK for PHP.
 *
 * Talks to the ShieldLabs API and verifies inbound webhooks. Your code decides
 * what to do with the score: you set the rules. This SDK never makes the
 * decision for you.
 *
 * Status: pre-launch scaffold. The surface below is a placeholder and will be
 * finalized from the OpenAPI specification before the first release.
 */
final class Client
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $baseUrl = null,
    ) {
    }

    /** Fetch a stored identification result by request id. Not implemented yet. */
    public function getResult(string $requestId): array
    {
        throw new \RuntimeException('shieldlabs/shieldlabs is not published yet. See https://shieldlabs.ai');
    }

    /** Verify the signature of an inbound ShieldLabs webhook. Not implemented yet. */
    public static function verifyWebhook(string $payload, string $signature, string $secret): bool
    {
        throw new \RuntimeException('shieldlabs/shieldlabs is not published yet.');
    }
}
