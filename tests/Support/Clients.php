<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

use ShieldLabs\ShieldLabs;
use ShieldLabs\ShieldLabsManagement;

/**
 * Builds clients wired to a mock HTTP client and a virtual clock.
 */
final class Clients
{
    public const API_KEY = 'sec_abcd1234-efgh5678-ijkl9012';
    public const SECRET_KEY = '0123456789abcdef0123456789abcdef';

    /**
     * @param array<string, mixed> $options
     */
    public static function history(MockHttpClient $http, ?FakeClock $clock = null, array $options = []): ShieldLabs
    {
        /** @phpstan-ignore argument.type */
        return new ShieldLabs($options + [
            'api_key' => self::API_KEY,
            'http_client' => $http,
            'clock' => $clock ?? new FakeClock(),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function management(MockHttpClient $http, ?FakeClock $clock = null, array $options = []): ShieldLabsManagement
    {
        /** @phpstan-ignore argument.type */
        return new ShieldLabsManagement($options + [
            'secret_key' => self::SECRET_KEY,
            'domain' => 'example.com',
            'http_client' => $http,
            'clock' => $clock ?? new FakeClock(),
        ]);
    }

    /**
     * A History response with one row whose request_id is $requestId.
     */
    public static function rowFor(string $requestId, int $score = 10, string $createdAt = '2026-09-30 12:40:01.007'): \Psr\Http\Message\ResponseInterface
    {
        $page = Fixtures::json('history-page.json');
        \assert(\is_array($page['data']) && \is_array($page['data'][1]));
        $row = $page['data'][1];
        $row['request_id'] = $requestId;
        $row['score'] = $score;
        $row['created_at'] = $createdAt;

        return MockHttpClient::json(200, ['data' => [$row], 'total' => 1]);
    }
}
