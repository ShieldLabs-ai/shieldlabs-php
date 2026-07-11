<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * ShieldLabs server SDK: webhook verification and History API client.
 */
final class Client
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = 'https://account.shieldlabs.ai/api')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Verify X-Shield-Signature against HMAC-SHA256(secret, raw body).
     * Comparison is constant-time via hash_equals.
     */
    public static function verifyWebhook(string $payload, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * GET /api/v1/history/{search_type}/{value} → ['data' => ..., 'total' => ...]
     *
     * @return array{data: list<mixed>, total: int|float}
     */
    public function getHistory(string $searchType, string $value, ?int $limit = null, ?int $offset = null): array
    {
        $url = $this->baseUrl . '/api/v1/history/' . rawurlencode($searchType) . '/' . rawurlencode($value);
        $query = [];
        if ($limit !== null) {
            $query['limit'] = (string) $limit;
        }
        if ($offset !== null) {
            $query['offset'] = (string) $offset;
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            throw new \RuntimeException('ShieldLabs History API error: ' . $status);
        }
        /** @var array{data: list<mixed>, total: int|float} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
