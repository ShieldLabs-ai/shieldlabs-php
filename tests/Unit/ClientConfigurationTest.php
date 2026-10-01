<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Http\CurlClient;
use ShieldLabs\Internal\HttpClientFactory;
use ShieldLabs\LookupType;
use ShieldLabs\ShieldLabs;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\ErrorLog;
use ShieldLabs\Tests\Support\MockHttpClient;

final class ClientConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SHIELDLABS_API_KEY');
        putenv('SHIELDLABS_API_BASE_URL');
    }

    protected function tearDown(): void
    {
        putenv('SHIELDLABS_API_KEY');
        putenv('SHIELDLABS_API_BASE_URL');
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Private API Key is required');
        new ShieldLabs(['http_client' => new MockHttpClient()]);
    }

    public function testRejectsAnEmptyApiKeyEvenWhenTheEnvironmentHasOne(): void
    {
        putenv('SHIELDLABS_API_KEY=' . Clients::API_KEY);

        $this->expectException(ValidationException::class);
        new ShieldLabs(['api_key' => '   ', 'http_client' => new MockHttpClient()]);
    }

    public function testReadsTheApiKeyFromTheEnvironment(): void
    {
        putenv('SHIELDLABS_API_KEY=' . Clients::API_KEY);
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        (new ShieldLabs(['http_client' => $http]))->history->search(LookupType::Ip, '192.0.2.10');

        self::assertSame('Bearer ' . Clients::API_KEY, $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testTreatsFalseFromGetenvAsNotSet(): void
    {
        putenv('SHIELDLABS_API_KEY=' . Clients::API_KEY);
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        (new ShieldLabs(['api_key' => false, 'base_url' => false, 'http_client' => $http]))->history->search(LookupType::Ip, '192.0.2.10');

        self::assertSame('Bearer ' . Clients::API_KEY, $http->lastRequest()->getHeaderLine('Authorization'));
        self::assertStringStartsWith('https://account.shieldlabs.ai/api/v1/', (string) $http->lastRequest()->getUri());
    }

    public function testExplainsAMissingKeyWhenGetenvReturnsFalse(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('set SHIELDLABS_API_KEY');
        new ShieldLabs(['api_key' => getenv('SHIELDLABS_API_KEY'), 'http_client' => new MockHttpClient()]);
    }

    public function testTrimsTheApiKey(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        (new ShieldLabs(['api_key' => ' ' . Clients::API_KEY . "\n", 'http_client' => $http]))->history->search(LookupType::Ip, '192.0.2.10');

        self::assertSame('Bearer ' . Clients::API_KEY, $http->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysThatCannotBeSent(): iterable
    {
        yield 'line feed' => ["sec_abcd1234-efgh5678-ijkl9012\nX-Injected: 1"];
        yield 'carriage return' => ["sec_abcd1234-efgh5678\rijkl9012"];
        yield 'NUL byte' => ["sec_abcd1234\0efgh5678-ijkl9012"];
        yield 'inner space' => ['sec_abcd1234 efgh5678-ijkl9012'];
        yield 'tab' => ["sec_abcd1234\tefgh5678-ijkl9012"];
        yield 'DEL' => ["sec_abcd1234\x7Fefgh5678-ijkl9012"];
        yield 'non-ASCII' => ['sec_abcd1234-efgh5678-ijkl901é'];
    }

    #[DataProvider('keysThatCannotBeSent')]
    public function testRejectsAKeyThatCannotBeSentInAHeader(string $key): void
    {
        $http = new MockHttpClient();

        // An environment variable cannot hold a NUL byte, so that case is only passed directly.
        foreach (str_contains($key, "\0") ? [['api_key' => $key]] : [['api_key' => $key], []] as $options) {
            putenv('SHIELDLABS_API_KEY=' . str_replace("\0", '', $key));
            try {
                new ShieldLabs($options + ['http_client' => $http]);
                self::fail('Expected a ValidationException');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('The API key contains characters that cannot be sent in an HTTP header', $exception->getMessage());
                self::assertStringNotContainsString('abcd1234', $exception->getMessage(), 'the key is never repeated');
                self::assertNull($exception->getPrevious());
            }
        }
        self::assertSame([], $http->requests);
    }

    public function testNeverRepeatsAHeaderValueThatThePsr7LayerRefuses(): void
    {
        $refused = new \InvalidArgumentException('"Bearer ' . Clients::API_KEY . '" is not valid header value.');
        $request = self::createStub(RequestInterface::class);
        $request->method('withHeader')->willThrowException($refused);
        $factory = self::createStub(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturn($request);
        $badUri = self::createStub(RequestFactoryInterface::class);
        $badUri->method('createRequest')->willThrowException(new \InvalidArgumentException('Unable to parse URI'));
        $http = new MockHttpClient();

        foreach ([$factory, $badUri] as $requestFactory) {
            $client = new ShieldLabs(['api_key' => Clients::API_KEY, 'http_client' => $http, 'request_factory' => $requestFactory]);
            try {
                $client->history->search(LookupType::Ip, '192.0.2.10');
                self::fail('Expected a ValidationException');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('The request could not be built', $exception->getMessage());
                self::assertStringNotContainsString(Clients::API_KEY, $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
        self::assertSame([], $http->requests);
    }

    public function testWarnsButWorksWithAnUnusualKey(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $log = ErrorLog::during(static function () use ($http): void {
            $client = new ShieldLabs(['api_key' => '0123456789abcdef0123456789abcdef', 'http_client' => $http]);
            $client->history->search(LookupType::Ip, '192.0.2.10');
        });

        self::assertCount(1, $log->warnings());
        self::assertStringContainsString('does not look like a Private API Key', $log->warnings()[0]);
        self::assertStringNotContainsString('0123456789abcdef', $log->contents, 'the key is never logged');
        self::assertCount(1, $http->requests);
    }

    public function testLogsTheKeyWarningOncePerProcess(): void
    {
        $log = ErrorLog::during(static function (): void {
            new ShieldLabs(['api_key' => 'sec_your_private_key', 'http_client' => new MockHttpClient()]);
            new ShieldLabs(['api_key' => 'sec_your_private_key', 'http_client' => new MockHttpClient()]);
            new ShieldLabs(['api_key' => 'legacy-key', 'http_client' => new MockHttpClient()]);
        });

        self::assertCount(1, $log->warnings());
    }

    public function testTheKeyWarningNeverReachesTheOutputOrAnErrorHandler(): void
    {
        $errors = [];
        $output = '';
        $client = null;
        $log = ErrorLog::during(static function () use (&$errors, &$output, &$client): void {
            set_error_handler(static function (int $level, string $message) use (&$errors): bool {
                $errors[] = $message;

                throw new \ErrorException($message, 0, $level);
            });
            $displayErrors = ini_set('display_errors', '1');
            ob_start();
            try {
                $client = new ShieldLabs(['api_key' => 'legacy-key', 'http_client' => new MockHttpClient()]);
            } finally {
                $output = (string) ob_get_clean();
                ini_set('display_errors', $displayErrors === false ? '' : $displayErrors);
                restore_error_handler();
            }
        });

        self::assertInstanceOf(ShieldLabs::class, $client);
        self::assertSame([], $errors, 'no PHP error is raised, so a framework error handler cannot turn it into an exception');
        self::assertSame('', $output, 'nothing is printed into the response');
        self::assertCount(1, $log->warnings());
    }

    public function testDoesNotWarnForAPrivateApiKey(): void
    {
        $log = ErrorLog::during(static function (): void {
            new ShieldLabs(['api_key' => Clients::API_KEY, 'http_client' => new MockHttpClient()]);
        });

        self::assertSame('', $log->contents);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['apiKey' => 'x'], 'Unknown option "apiKey"'];
        yield 'numeric key' => [[0 => 'x'], 'Unknown option "0"'];
        yield 'zero timeout' => [['timeout' => 0], '"timeout" must be greater than zero'];
        yield 'negative timeout' => [['timeout' => -5], '"timeout" must be greater than zero'];
        yield 'timeout as string' => [['timeout' => '10'], '"timeout" must be a number'];
        yield 'negative retries' => [['max_retries' => -1], '"max_retries" must be an integer'];
        yield 'retries as float' => [['max_retries' => 2.0], '"max_retries" must be an integer'];
        yield 'api key not a string' => [['api_key' => 123], '"api_key" must be a string'];
        yield 'base url not a string' => [['base_url' => 8080], '"base_url" must be a string'];
        yield 'allow_insecure_http not a boolean' => [['allow_insecure_http' => 'yes'], '"allow_insecure_http" must be a boolean'];
        yield 'http client of wrong type' => [['http_client' => new \stdClass()], 'Psr\Http\Client\ClientInterface'];
        yield 'request factory of wrong type' => [['request_factory' => 'factory'], 'RequestFactoryInterface'];
        yield 'clock of wrong type' => [['clock' => new \stdClass()], 'internal'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testValidatesOptions(array $options, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        /** @phpstan-ignore argument.type */
        new ShieldLabs($options + ['api_key' => Clients::API_KEY, 'http_client' => new MockHttpClient()]);
    }

    public function testUsesAnInjectedRequestFactory(): void
    {
        $factory = new class implements RequestFactoryInterface {
            public int $calls = 0;

            public function createRequest(string $method, $uri): RequestInterface
            {
                ++$this->calls;

                return (new Psr17Factory())->createRequest($method, $uri)->withHeader('X-Test', 'yes');
            }
        };
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        (new ShieldLabs(['api_key' => Clients::API_KEY, 'http_client' => $http, 'request_factory' => $factory]))
            ->history->search(LookupType::Ip, '192.0.2.10');

        self::assertSame(1, $factory->calls);
        self::assertSame('yes', $http->lastRequest()->getHeaderLine('X-Test'));
    }

    public function testFallsBackToTheBuiltInCurlClient(): void
    {
        self::assertInstanceOf(CurlClient::class, HttpClientFactory::client(null, 3.0));
        self::assertInstanceOf(RequestFactoryInterface::class, HttpClientFactory::requestFactory(null));
        self::assertInstanceOf(ShieldLabs::class, new ShieldLabs(['api_key' => Clients::API_KEY]));
    }

    public function testCurlClientRejectsNonPositiveTimeouts(): void
    {
        $this->expectException(ValidationException::class);
        new CurlClient(0.0);
    }

    public function testCurlClientRejectsARequestWithoutUri(): void
    {
        $this->expectException(\Psr\Http\Client\RequestExceptionInterface::class);
        (new CurlClient(1.0))->sendRequest(new Request('GET', ''));
    }
}
