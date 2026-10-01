<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Http\Discovery\ClassDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Http\CurlClient;
use ShieldLabs\Internal\ConfiguredClient;
use ShieldLabs\Internal\HttpClientFactory;
use ShieldLabs\ShieldLabs;
use ShieldLabs\ShieldLabsManagement;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\ErrorLog;
use ShieldLabs\Tests\Support\MockHttpClient;
use ShieldLabs\Tests\Support\StubDiscoveryStrategy;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Component\HttpClient\OlderStubHttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;
use Symfony\Component\HttpClient\StubHttpClient;

/**
 * The default HTTP client must always apply the `timeout` option. Tests that load the
 * Guzzle or Symfony stand-ins run in a separate process, because discovery would find
 * those classes in every later test.
 */
final class HttpClientFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SHIELDLABS_API_BASE_URL');
        putenv('SHIELDLABS_MANAGEMENT_BASE_URL');
    }

    private static function loadStubs(string ...$names): void
    {
        foreach ($names as $name) {
            require_once \dirname(__DIR__) . '/Support/Stubs/' . $name . '.php';
        }
        Psr18ClientDiscovery::clearCache();
    }

    /**
     * Runs $test while discovery offers MockHttpClient as the installed PSR-18 client,
     * a client the SDK cannot configure.
     *
     * @param \Closure(): void $test
     */
    private static function withUnknownDiscoveredClient(\Closure $test): void
    {
        $strategies = ClassDiscovery::getStrategies();
        $strategies = \is_array($strategies) ? $strategies : iterator_to_array($strategies);
        Psr18ClientDiscovery::prependStrategy(StubDiscoveryStrategy::class);
        Psr18ClientDiscovery::clearCache();
        try {
            $test();
        } finally {
            ClassDiscovery::setStrategies($strategies);
            Psr18ClientDiscovery::clearCache();
        }
    }

    private static function httpClientOf(ShieldLabs|ShieldLabsManagement $client): ClientInterface
    {
        $owner = $client instanceof ShieldLabs ? $client->history : $client;
        $transport = (new \ReflectionProperty($owner, 'transport'))->getValue($owner);
        \assert(\is_object($transport));
        $http = (new \ReflectionProperty($transport, 'client'))->getValue($transport);
        \assert($http instanceof ClientInterface);

        return $http;
    }

    private static function curlTimeout(ClientInterface $client): mixed
    {
        self::assertInstanceOf(CurlClient::class, $client);

        return (new \ReflectionProperty($client, 'timeout'))->getValue($client);
    }

    /**
     * The Guzzle stand-in inside a client the SDK configured, and its timeout.
     *
     * @return array{GuzzleClient, float}
     */
    private static function guzzleOf(ClientInterface $client): array
    {
        self::assertInstanceOf(ConfiguredClient::class, $client);
        self::assertInstanceOf(GuzzleClient::class, $client->client);

        return [$client->client, $client->getTimeout()];
    }

    /**
     * The Symfony HttpClient stand-in inside a client the SDK configured.
     */
    private static function symfonyOf(ClientInterface $client): StubHttpClient|OlderStubHttpClient
    {
        self::assertInstanceOf(ConfiguredClient::class, $client);
        self::assertInstanceOf(SymfonyPsr18Client::class, $client->client);
        self::assertNotNull($client->client->responseFactory);
        self::assertNotNull($client->client->streamFactory);
        $httpClient = $client->client->client;
        self::assertTrue($httpClient instanceof StubHttpClient || $httpClient instanceof OlderStubHttpClient);

        return $httpClient;
    }

    public function testKeepsAnInjectedClientAsIs(): void
    {
        $http = new MockHttpClient();

        self::assertSame($http, HttpClientFactory::client($http, 3.0));
        self::assertSame($http, HttpClientFactory::client($http, 3.0, false));
    }

    public function testUsesTheBuiltInClientWithTheTimeoutWhenNoClientIsDiscovered(): void
    {
        $log = ErrorLog::during(static function (): void {
            self::assertSame(3.0, self::curlTimeout(HttpClientFactory::client(null, 3.0)));
            self::assertSame(4.5, self::curlTimeout(self::httpClientOf(new ShieldLabs(['api_key' => Clients::API_KEY, 'timeout' => 4.5]))));
        });

        self::assertSame('', $log->contents);
    }

    public function testFailsWhenNoClientIsDiscoveredAndCurlIsMissing(): void
    {
        $this->expectException(ShieldLabsException::class);
        $this->expectExceptionMessage('No PSR-18 HTTP client was found and ext-curl is not loaded');

        HttpClientFactory::client(null, 3.0, false);
    }

    public function testPrefersTheBuiltInClientOverADiscoveredClientItCannotConfigure(): void
    {
        $log = ErrorLog::during(static function (): void {
            self::withUnknownDiscoveredClient(static function (): void {
                self::assertSame(3.0, self::curlTimeout(HttpClientFactory::client(null, 3.0)));
            });
        });

        self::assertSame('', $log->contents);
    }

    public function testUsesADiscoveredClientItCannotConfigureWithoutCurlAndWarnsOnce(): void
    {
        $log = ErrorLog::during(static function (): void {
            self::withUnknownDiscoveredClient(static function (): void {
                self::assertInstanceOf(MockHttpClient::class, HttpClientFactory::client(null, 3.0, false));
                self::assertInstanceOf(MockHttpClient::class, HttpClientFactory::client(null, 3.0, false));
            });
        });

        $warnings = $log->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('the "timeout" option cannot be applied to the discovered HTTP client ' . MockHttpClient::class, $warnings[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatesADiscoveredGuzzleClientWithTheTimeout(): void
    {
        self::loadStubs('guzzle');

        $client = null;
        $log = ErrorLog::during(static function () use (&$client): void {
            $client = HttpClientFactory::client(null, 3.0);
        });

        \assert($client instanceof ClientInterface);
        [$guzzle, $timeout] = self::guzzleOf($client);
        self::assertSame(3.0, $timeout);
        self::assertSame(['timeout' => 3.0, 'connect_timeout' => 3.0], $guzzle->config);
        self::assertSame('', $log->contents);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatesTheGuzzleClientAgainWithAShorterTimeoutForOnePoll(): void
    {
        self::loadStubs('guzzle');
        $client = HttpClientFactory::client(null, 3.0);
        self::assertInstanceOf(ConfiguredClient::class, $client);

        $shorter = $client->withTimeout(1.5);

        [$guzzle, $timeout] = self::guzzleOf($shorter);
        self::assertSame(1.5, $timeout);
        self::assertSame(['timeout' => 1.5, 'connect_timeout' => 1.5], $guzzle->config);
        self::assertSame(['timeout' => 3.0, 'connect_timeout' => 3.0], self::guzzleOf($client)[0]->config);
        self::assertSame($client, $client->withTimeout(3.0));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGuzzleClientsWithAnotherTimeoutShareOneHandlerStack(): void
    {
        self::loadStubs('guzzle', 'guzzle-handler-stack');
        $client = HttpClientFactory::client(null, 3.0);
        self::assertInstanceOf(ConfiguredClient::class, $client);

        $config = self::guzzleOf($client)[0]->config;
        $shorter = self::guzzleOf($client->withTimeout(1.5))[0]->config;

        self::assertInstanceOf(HandlerStack::class, $config['handler']);
        self::assertSame($config['handler'], $shorter['handler']);
        self::assertSame([3.0, 3.0], [$config['timeout'], $config['connect_timeout']]);
        self::assertSame([1.5, 1.5], [$shorter['timeout'], $shorter['connect_timeout']]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPassesTheTimeoutOptionOfBothClientsToAGuzzleClient(): void
    {
        self::loadStubs('guzzle');

        $history = self::httpClientOf(new ShieldLabs(['api_key' => Clients::API_KEY, 'timeout' => 4]));
        $management = self::httpClientOf(new ShieldLabsManagement(['secret_key' => Clients::SECRET_KEY, 'domain' => 'example.com', 'timeout' => 2.5]));

        self::assertSame(['timeout' => 4.0, 'connect_timeout' => 4.0], self::guzzleOf($history)[0]->config);
        self::assertSame(['timeout' => 2.5, 'connect_timeout' => 2.5], self::guzzleOf($management)[0]->config);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatesADiscoveredSymfonyClientWithTheTimeout(): void
    {
        self::loadStubs('symfony-psr18-client', 'symfony-http-client');

        $client = null;
        $log = ErrorLog::during(static function () use (&$client): void {
            $client = HttpClientFactory::client(null, 3.0);
        });

        \assert($client instanceof ClientInterface);
        self::assertSame(['timeout' => 3.0, 'max_duration' => 3.0, 'max_redirects' => 0], self::symfonyOf($client)->options);
        self::assertSame('', $log->contents);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDerivesTheSymfonyClientWithAShorterTimeoutForOnePoll(): void
    {
        self::loadStubs('symfony-psr18-client', 'symfony-http-client');
        $client = HttpClientFactory::client(null, 3.0);
        self::assertInstanceOf(ConfiguredClient::class, $client);

        $base = self::symfonyOf($client);
        $shorter = self::symfonyOf($client->withTimeout(1.5));

        self::assertInstanceOf(StubHttpClient::class, $shorter);
        self::assertSame($base, $shorter->derivedFrom);
        self::assertSame(['timeout' => 1.5, 'max_duration' => 1.5, 'max_redirects' => 0], $shorter->options);
        self::assertSame(['timeout' => 3.0, 'max_duration' => 3.0, 'max_redirects' => 0], $base->options);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatesAnotherSymfonyClientWhenWithOptionsIsMissing(): void
    {
        self::loadStubs('symfony-psr18-client', 'symfony-http-client');
        SymfonyHttpClient::$withoutWithOptions = true;
        $client = HttpClientFactory::client(null, 3.0);
        self::assertInstanceOf(ConfiguredClient::class, $client);

        $shorter = self::symfonyOf($client->withTimeout(1.5));

        self::assertInstanceOf(OlderStubHttpClient::class, $shorter);
        self::assertNotSame(self::symfonyOf($client), $shorter);
        self::assertSame(['timeout' => 1.5, 'max_duration' => 1.5, 'max_redirects' => 0], $shorter->options);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFallsBackToTheBuiltInClientWhenGuzzleRejectsTheOptions(): void
    {
        self::loadStubs('guzzle');
        GuzzleClient::$rejectOptions = true;

        self::assertSame(3.0, self::curlTimeout(HttpClientFactory::client(null, 3.0)));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFallsBackToTheBuiltInClientWhenTheSymfonyFactoryIsMissing(): void
    {
        self::loadStubs('symfony-psr18-client');

        self::assertSame(3.0, self::curlTimeout(HttpClientFactory::client(null, 3.0)));
    }
}
