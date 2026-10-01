<?php

declare(strict_types=1);

namespace ShieldLabs;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\BaseUrl;
use ShieldLabs\Internal\HttpClientFactory;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\Transport;
use ShieldLabs\Internal\UserAgent;
use ShieldLabs\Internal\Validate;
use ShieldLabs\Model\DomainProfile;

/**
 * Management API client, authenticated with the Secret Key and the registered
 * domain. The Management API allows about 15 requests per minute per client IP and
 * then blocks that IP for 10 minutes, so this client never retries a 429: call it
 * sparingly and cache the profile.
 */
final class ShieldLabsManagement
{
    public const DEFAULT_BASE_URL = 'https://api.shieldlabs.ai';

    private readonly Transport $transport;
    private readonly string $domain;

    /**
     * Options:
     *
     * - secret_key: Secret Key of the domain (visible ASCII characters); when omitted, SHIELDLABS_SECRET_KEY is read
     * - domain: registered domain (normalized: lowercase, no scheme, path or leading "www."; an international domain in its punycode form); when omitted, SHIELDLABS_DOMAIN is read
     * - base_url: default https://api.shieldlabs.ai (or SHIELDLABS_MANAGEMENT_BASE_URL); a trailing "/v1" is removed. It must use https; plain http is accepted for localhost, 127.0.0.1 and [::1]
     * - allow_insecure_http, timeout, max_retries, http_client, request_factory: as for {@see ShieldLabs}
     *
     * @param array{
     *     secret_key?: string|false|null,
     *     domain?: string|false|null,
     *     base_url?: string|false|null,
     *     allow_insecure_http?: bool,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     http_client?: ClientInterface|null,
     *     request_factory?: RequestFactoryInterface|null,
     * } $options
     *
     * @throws ValidationException
     * @throws ShieldLabsException when no HTTP client is available
     */
    public function __construct(array $options = [])
    {
        Options::assertKnown(
            $options,
            ['secret_key', 'domain', 'base_url', 'allow_insecure_http', 'timeout', 'max_retries', 'http_client', 'request_factory', 'clock'],
            'ShieldLabsManagement',
        );

        $secretKey = trim(Options::stringOrEnv($options, 'secret_key', 'SHIELDLABS_SECRET_KEY') ?? '');
        if ($secretKey === '') {
            throw new ValidationException('A Secret Key is required: pass "secret_key" or set SHIELDLABS_SECRET_KEY.');
        }
        Validate::headerValue($secretKey, 'The Secret Key');
        $domain = Options::stringOrEnv($options, 'domain', 'SHIELDLABS_DOMAIN');
        if ($domain === null) {
            throw new ValidationException('The registered domain is required: pass "domain" or set SHIELDLABS_DOMAIN.');
        }
        $this->domain = self::normalizeDomain($domain);

        $baseUrl = BaseUrl::normalize(
            Options::stringOrEnv($options, 'base_url', 'SHIELDLABS_MANAGEMENT_BASE_URL') ?? self::DEFAULT_BASE_URL,
            'base_url',
            '/v1',
            Options::boolean($options, 'allow_insecure_http', false),
        );
        $timeout = Options::seconds($options, 'timeout', ShieldLabs::DEFAULT_TIMEOUT, false);
        $maxRetries = Options::integer($options, 'max_retries', ShieldLabs::DEFAULT_MAX_RETRIES, 0);

        $this->transport = new Transport(
            HttpClientFactory::client($options['http_client'] ?? null, $timeout),
            HttpClientFactory::requestFactory($options['request_factory'] ?? null),
            $baseUrl,
            [
                'Authorization' => 'Bearer ' . $secretKey,
                'X-Shield-Domain' => $this->domain,
                'Accept' => 'application/json',
                'User-Agent' => UserAgent::value(),
            ],
            $maxRetries,
            false,
            ShieldLabs::clock(Options::get($options, 'clock')),
        );
    }

    /**
     * The domain as sent in X-Shield-Domain (after normalization).
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * `GET /v1/profile`: remaining identifications, masked keys and creation time.
     *
     * @throws ShieldLabsException
     */
    public function getProfile(): DomainProfile
    {
        $body = $this->transport->getJson('/v1/profile');
        if (!\is_array($body)) {
            throw new ApiException('The Management API returned an unexpected response body.', 200, $body);
        }

        return DomainProfile::fromArray($body);
    }

    /**
     * Normalizes a domain the way the server stores it: trimmed, lowercase, without
     * scheme, path, trailing slash or a leading "www.". The result is sent in the
     * X-Shield-Domain header, so it must consist of visible ASCII characters: pass an
     * international domain in its punycode form (xn--...).
     *
     * @throws ValidationException when nothing usable remains or the domain cannot be sent in a header
     */
    public static function normalizeDomain(string $domain): string
    {
        $normalized = strtolower(trim($domain));
        $normalized = preg_replace('#^(?:[a-z][a-z0-9+.\-]*:)?//#', '', $normalized) ?? $normalized;
        $normalized = substr($normalized, 0, strcspn($normalized, '/?#'));
        if (str_starts_with($normalized, 'www.')) {
            $normalized = substr($normalized, 4);
        }
        if ($normalized === '') {
            throw new ValidationException('No domain name is left after normalization: pass the registered domain, for example example.com.');
        }
        if (preg_match('/[\x80-\xFF]/', $normalized) === 1) {
            throw new ValidationException('The domain must be ASCII: pass an international domain in its punycode form (xn--...).');
        }

        return Validate::headerValue($normalized, 'The domain');
    }
}
