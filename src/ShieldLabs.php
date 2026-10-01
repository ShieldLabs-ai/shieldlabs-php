<?php

declare(strict_types=1);

namespace ShieldLabs;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\BaseUrl;
use ShieldLabs\Internal\Clock;
use ShieldLabs\Internal\HttpClientFactory;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\SystemClock;
use ShieldLabs\Internal\Transport;
use ShieldLabs\Internal\UserAgent;
use ShieldLabs\Internal\Validate;
use ShieldLabs\Internal\Warning;
use ShieldLabs\Resource\History;
use ShieldLabs\Resource\Identifications;

/**
 * History API client, authenticated with the Private API Key (`sec_...`) of one
 * domain. The client keeps no per-request state: create it once and reuse it.
 *
 * ```php
 * $client = new ShieldLabs\ShieldLabs(['api_key' => 'sec_your_private_key']);
 * $identification = $client->identifications->get($requestId);
 * ```
 */
final class ShieldLabs
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://account.shieldlabs.ai';
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_MAX_RETRIES = 2;

    private const API_KEY_PATTERN = '/^sec_[a-z0-9]{8}-[a-z0-9]{8}-[a-z0-9]{8}$/D';

    /** History lookups by identifier. */
    public readonly History $history;

    /** Verdict for one request ID, with wait-for-verdict polling. */
    public readonly Identifications $identifications;

    /**
     * Options:
     *
     * - api_key: Private API Key (visible ASCII characters); when omitted, SHIELDLABS_API_KEY is read from the environment
     * - base_url: origin of the History API (default https://account.shieldlabs.ai, or SHIELDLABS_API_BASE_URL); a trailing "/api" is removed. It must use https; plain http is accepted for localhost, 127.0.0.1 and [::1]
     * - allow_insecure_http: accept a plain http base_url on another host, for a test server (default false); the key then travels unencrypted
     * - timeout: seconds per HTTP attempt (default 10); applied to the built-in client and to a discovered Guzzle or Symfony HttpClient, not to an injected http_client
     * - max_retries: retries for connection errors, timeouts, 429 and 5xx (default 2)
     * - http_client: a PSR-18 client, used as is; by default a discovered Guzzle or Symfony HttpClient (created with the timeout), else the built-in cURL client
     * - request_factory: a PSR-17 request factory; discovered by default
     *
     * @param array{
     *     api_key?: string|false|null,
     *     base_url?: string|false|null,
     *     allow_insecure_http?: bool,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     http_client?: ClientInterface|null,
     *     request_factory?: RequestFactoryInterface|null,
     * } $options
     *
     * @throws ValidationException when the API key is empty or cannot be sent in a header, or an option is invalid
     * @throws ShieldLabsException when no HTTP client is available
     */
    public function __construct(array $options = [])
    {
        Options::assertKnown(
            $options,
            ['api_key', 'base_url', 'allow_insecure_http', 'timeout', 'max_retries', 'http_client', 'request_factory', 'clock'],
            'ShieldLabs',
        );

        $apiKey = trim(Options::stringOrEnv($options, 'api_key', 'SHIELDLABS_API_KEY') ?? '');
        if ($apiKey === '') {
            throw new ValidationException('A Private API Key is required: pass "api_key" or set SHIELDLABS_API_KEY.');
        }
        Validate::headerValue($apiKey, 'The API key');
        if (preg_match(self::API_KEY_PATTERN, $apiKey) !== 1) {
            // Logged with error_log(), once per process: never part of a response, never fatal.
            Warning::once('the API key does not look like a Private API Key (sec_xxxxxxxx-xxxxxxxx-xxxxxxxx). Check that you did not pass the Public Key or the Secret Key.');
        }

        $baseUrl = BaseUrl::normalize(
            Options::stringOrEnv($options, 'base_url', 'SHIELDLABS_API_BASE_URL') ?? self::DEFAULT_BASE_URL,
            'base_url',
            '/api',
            Options::boolean($options, 'allow_insecure_http', false),
        );
        $timeout = Options::seconds($options, 'timeout', self::DEFAULT_TIMEOUT, false);
        $maxRetries = Options::integer($options, 'max_retries', self::DEFAULT_MAX_RETRIES, 0);
        $clock = self::clock(Options::get($options, 'clock'));

        $transport = new Transport(
            HttpClientFactory::client($options['http_client'] ?? null, $timeout),
            HttpClientFactory::requestFactory($options['request_factory'] ?? null),
            $baseUrl,
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'User-Agent' => UserAgent::value(),
            ],
            $maxRetries,
            true,
            $clock,
        );
        $this->history = new History($transport);
        $this->identifications = new Identifications($this->history, $clock);
    }

    /**
     * @internal
     *
     * @throws ValidationException
     */
    public static function clock(mixed $clock): Clock
    {
        if ($clock === null) {
            return new SystemClock();
        }
        if (!$clock instanceof Clock) {
            throw new ValidationException('Option "clock" is internal and must implement ShieldLabs\Internal\Clock.');
        }

        return $clock;
    }
}
