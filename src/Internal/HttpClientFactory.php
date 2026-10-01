<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Http\CurlClient;

/**
 * Picks the HTTP client so that the `timeout` option applies to every request the
 * SDK sends by default:
 *
 * 1. an injected client is used as is (it keeps its own timeout settings);
 * 2. a PSR-18 client discovered in the project is used when the SDK can pass the
 *    timeout to it: Guzzle 7 and Symfony HttpClient are created with it, wrapped in
 *    a {@see ConfiguredClient} so a poll can get a shorter timeout;
 * 3. otherwise the built-in cURL client;
 * 4. without ext-curl, any other discovered client, with a logged warning that the
 *    timeout does not apply to it.
 *
 * @internal
 */
final class HttpClientFactory
{
    private const GUZZLE_CLIENT = 'GuzzleHttp\Client';
    private const GUZZLE_HANDLER_STACK = 'GuzzleHttp\HandlerStack';
    private const SYMFONY_PSR18_CLIENT = 'Symfony\Component\HttpClient\Psr18Client';
    private const SYMFONY_HTTP_CLIENT = 'Symfony\Component\HttpClient\HttpClient';

    private function __construct() {}

    /**
     * @param float     $timeout    seconds per HTTP attempt
     * @param bool|null $curlLoaded whether ext-curl is available; detected when null
     *
     * @throws ShieldLabsException
     */
    public static function client(mixed $injected, float $timeout, ?bool $curlLoaded = null): ClientInterface
    {
        if ($injected !== null) {
            if (!$injected instanceof ClientInterface) {
                throw new ValidationException('Option "http_client" must implement Psr\Http\Client\ClientInterface.');
            }

            return $injected;
        }

        $discovered = self::discover();
        if ($discovered !== null) {
            $configured = self::withTimeout($discovered, $timeout);
            if ($configured !== null) {
                return $configured;
            }
        }

        if ($curlLoaded ?? \extension_loaded('curl')) {
            return new CurlClient($timeout);
        }

        if ($discovered !== null) {
            Warning::once(\sprintf(
                'the "timeout" option cannot be applied to the discovered HTTP client %s, so requests may wait '
                . 'as long as that client allows. Pass a client configured with a timeout as "http_client", or enable ext-curl.',
                $discovered::class,
            ));

            return $discovered;
        }

        throw new ShieldLabsException(
            'No PSR-18 HTTP client was found and ext-curl is not loaded. Install a PSR-18 client '
            . '(for example guzzlehttp/guzzle or symfony/http-client) or enable ext-curl.',
        );
    }

    /**
     * @throws ValidationException
     */
    public static function requestFactory(mixed $injected): RequestFactoryInterface
    {
        if ($injected !== null) {
            if (!$injected instanceof RequestFactoryInterface) {
                throw new ValidationException('Option "request_factory" must implement Psr\Http\Message\RequestFactoryInterface.');
            }

            return $injected;
        }

        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (DiscoveryException) {
            return new Psr17Factory();
        }
    }

    private static function discover(): ?ClientInterface
    {
        try {
            return Psr18ClientDiscovery::find();
        } catch (DiscoveryException) {
            return null;
        }
    }

    /**
     * A new instance of a discovered client with the timeout applied, or null when
     * the SDK does not know how to configure that client. Discovery creates these
     * clients without options, so nothing configured by the project is lost.
     */
    private static function withTimeout(ClientInterface $discovered, float $timeout): ?ConfiguredClient
    {
        $class = $discovered::class;
        $createSymfonyClient = [self::SYMFONY_HTTP_CLIENT, 'create'];

        try {
            if (self::is($class, self::GUZZLE_CLIENT)) {
                // Clients with another timeout share one handler stack, so they share
                // its open connections. "timeout" limits the whole request,
                // "connect_timeout" the connection.
                $createStack = [self::GUZZLE_HANDLER_STACK, 'create'];
                $shared = \is_callable($createStack) ? ['handler' => $createStack()] : [];

                return new ConfiguredClient(
                    static fn(float $seconds): ClientInterface => new $class($shared + ['timeout' => $seconds, 'connect_timeout' => $seconds]),
                    $timeout,
                );
            }
            if (self::is($class, self::SYMFONY_PSR18_CLIENT) && \is_callable($createSymfonyClient)) {
                // "timeout" limits the idle time, "max_duration" the whole request. Like the
                // built-in client, never follow redirects. A client with another timeout is
                // derived with withOptions() when the installed version has it.
                $createSymfony = \Closure::fromCallable($createSymfonyClient);
                $httpClient = $createSymfony(['timeout' => $timeout, 'max_duration' => $timeout, 'max_redirects' => 0]);
                $factory = new Psr17Factory();

                return new ConfiguredClient(
                    static function (float $seconds) use ($class, $httpClient, $factory, $timeout, $createSymfony): ClientInterface {
                        $client = $httpClient;
                        if ($seconds !== $timeout) {
                            $withOptions = [$httpClient, 'withOptions'];
                            $client = \is_callable($withOptions)
                                ? $withOptions(['timeout' => $seconds, 'max_duration' => $seconds])
                                : $createSymfony(['timeout' => $seconds, 'max_duration' => $seconds, 'max_redirects' => 0]);
                        }

                        return new $class($client, $factory, $factory);
                    },
                    $timeout,
                );
            }
        } catch (\Throwable) {
            // An incompatible major version: let the caller fall back to the built-in client.
            return null;
        }

        return null;
    }

    private static function is(string $class, string $expected): bool
    {
        return strcasecmp($class, $expected) === 0;
    }
}
