<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Integration;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ShieldLabs\Exception\ApiTimeoutException;
use ShieldLabs\Exception\AuthenticationException;
use ShieldLabs\Exception\BadRequestException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Http\CurlClient;
use ShieldLabs\Http\NetworkException;
use ShieldLabs\Http\RequestException;
use ShieldLabs\LookupType;
use ShieldLabs\ShieldLabs;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\PhpServer;

/**
 * The built-in PSR-18 client against a real HTTP server (`php -S`).
 */
final class CurlClientTest extends TestCase
{
    private static ?PhpServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new PhpServer(__DIR__ . '/servers/echo.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function url(string $path): string
    {
        \assert(self::$server !== null);

        return self::$server->url . $path;
    }

    public function testSendsHeadersAndParsesTheResponse(): void
    {
        $request = (new Request('GET', self::url('/echo?x=1')))
            ->withHeader('Authorization', 'Bearer token')
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'shieldlabs-php/test');

        $response = (new CurlClient(5.0))->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['one', 'two'], $response->getHeader('X-Multi'));
        $echo = json_decode((string) $response->getBody(), true);
        self::assertIsArray($echo);
        self::assertSame('GET', $echo['method']);
        self::assertSame('/echo?x=1', $echo['uri']);
        self::assertIsArray($echo['headers']);
        self::assertSame('Bearer token', $echo['headers']['authorization']);
        self::assertSame('shieldlabs-php/test', $echo['headers']['user-agent']);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testSendsARequestBody(): void
    {
        $request = (new Request('POST', self::url('/echo'), ['Content-Type' => 'application/json'], '{"a":1}'));

        $echo = json_decode((string) (new CurlClient(5.0))->sendRequest($request)->getBody(), true);

        self::assertIsArray($echo);
        self::assertSame('POST', $echo['method']);
        self::assertSame('{"a":1}', $echo['body']);
    }

    public function testHandlesHeadRequests(): void
    {
        $response = (new CurlClient(5.0))->sendRequest(new Request('HEAD', self::url('/echo')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testReturnsErrorStatusesAsResponses(): void
    {
        $response = (new CurlClient(5.0))->sendRequest(new Request('GET', self::url('/status/503')));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('status 503', (string) $response->getBody());
    }

    public function testDoesNotFollowRedirects(): void
    {
        $response = (new CurlClient(5.0))->sendRequest(new Request('GET', self::url('/redirect')));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://127.0.0.1:1/elsewhere', $response->getHeaderLine('Location'));
    }

    public function testReportsTimeouts(): void
    {
        try {
            (new CurlClient(0.3))->sendRequest(new Request('GET', self::url('/slow?ms=1500')));
            self::fail('Expected a timeout');
        } catch (NetworkException $exception) {
            self::assertTrue($exception->isTimeout());
            self::assertSame(28, $exception->getCode());
            self::assertStringContainsString('cURL error 28', $exception->getMessage());
            self::assertSame('GET', $exception->getRequest()->getMethod());
        }
    }

    public function testGivesACopyWithAnotherTimeout(): void
    {
        $client = new CurlClient(5.0, 2.0);
        $shorter = $client->withTimeout(1.5);

        self::assertSame(5.0, $client->getTimeout());
        self::assertSame(1.5, $shorter->getTimeout());
        self::assertNotSame($client, $shorter);
        self::assertSame($client, $client->withTimeout(5.0));
        self::assertSame(2.0, (new \ReflectionProperty($shorter, 'connectTimeout'))->getValue($shorter));

        try {
            (new CurlClient(5.0))->withTimeout(0.3)->sendRequest(new Request('GET', self::url('/slow?ms=800')));
            self::fail('Expected a timeout');
        } catch (NetworkException $exception) {
            self::assertTrue($exception->isTimeout());
        }

        $this->expectException(ValidationException::class);
        $client->withTimeout(0.0);
    }

    public function testReportsConnectionFailures(): void
    {
        try {
            (new CurlClient(2.0))->sendRequest(new Request('GET', 'http://127.0.0.1:1/'));
            self::fail('Expected a connection failure');
        } catch (NetworkException $exception) {
            self::assertFalse($exception->isTimeout());
        }
    }

    public function testRefusesHeaderValuesWithLineBreaks(): void
    {
        foreach (["Bearer token\r\nX-Injected: 1", "Bearer token\n", "Bearer\0token"] as $value) {
            $request = self::createStub(RequestInterface::class);
            $request->method('getUri')->willReturn(new Uri(self::url('/echo')));
            $request->method('getMethod')->willReturn('GET');
            $request->method('getHeaders')->willReturn(['Authorization' => [$value]]);
            $request->method('getBody')->willReturn(Stream::create(''));

            try {
                (new CurlClient(5.0))->sendRequest($request);
                self::fail('Expected a RequestException');
            } catch (RequestException $exception) {
                self::assertStringNotContainsString('token', $exception->getMessage());
            }
        }
    }

    public function testRefusesOtherProtocols(): void
    {
        $this->expectException(NetworkException::class);
        (new CurlClient(2.0))->sendRequest(new Request('GET', 'file:///etc/passwd'));
    }

    public function testTheSdkWorksOutOfTheBoxOverRealHttp(): void
    {
        \assert(self::$server !== null);
        $client = new ShieldLabs(['api_key' => Clients::API_KEY, 'base_url' => self::$server->url . '/api/']);

        $page = $client->history->search(LookupType::DeviceId, 'd8e0f2a4-b6c8-4d0e-bf2a-4b6c8d0e2f4a');

        self::assertSame(37, $page->total);
        self::assertCount(5, $page->data);
        self::assertSame('a5b7c9d1-e3f5-4a7b-9c1d-3e5f7a9b1c3d', $page->data[0]->request_id);
    }

    public function testTheSdkMapsARealAuthenticationError(): void
    {
        \assert(self::$server !== null);
        $client = new ShieldLabs(['api_key' => 'sec_zzzz0000-zzzz0000-zzzz0000', 'base_url' => self::$server->url]);

        try {
            $client->history->search(LookupType::Ip, '192.0.2.1');
            self::fail('Expected an AuthenticationException');
        } catch (AuthenticationException $exception) {
            self::assertSame('invalid api key', $exception->getErrorMessage());
        }
    }

    public function testTheSdkSendsUserHidsInCanonicalPathForm(): void
    {
        \assert(self::$server !== null);
        $client = new ShieldLabs(['api_key' => Clients::API_KEY, 'base_url' => self::$server->url . '/capture', 'max_retries' => 0]);

        foreach ([
            ['jane.doe+test@example.com', 'jane.doe+test@example.com'],
            ['a$b&c,d:e;f=g', 'a$b&c,d:e;f=g'],
            ["x!'()*y", 'x%21%27%28%29%2Ay'],
            ['Zoë ✓?#', 'Zo%C3%AB%20%E2%9C%93%3F%23'],
            ['%2F.', '%252F.'],
        ] as [$userHid, $segment]) {
            try {
                $client->history->search(LookupType::UserHid, $userHid);
                self::fail('Expected the capture route to answer 400');
            } catch (BadRequestException $exception) {
                self::assertSame('/capture/api/v1/history/user_hid/' . $segment . '?limit=20&offset=0', $exception->getErrorMessage());
            }
        }
    }

    /**
     * While identifications->get() waits, a poll is limited to the time left but never
     * to less than 1 s. The History stand-in here answers only after 1.5 s, so without
     * that limit both calls would return null instead of timing out.
     */
    public function testAPollIsLimitedToTheTimeLeftButAtLeastOneSecond(): void
    {
        $server = new PhpServer(__DIR__ . '/servers/echo.php');
        $cases = [
            'the built-in client, no time left' => [[], 0.0, 1.0],
            'a CurlClient passed in, 1.25 s left' => [['http_client' => new CurlClient(10.0)], 1.25, 1.25],
        ];

        try {
            foreach ($cases as $name => [$options, $budget, $expected]) {
                $client = new ShieldLabs($options + ['api_key' => Clients::API_KEY, 'base_url' => $server->url . '/slowapi']);
                $started = microtime(true);
                try {
                    $client->identifications->get('3b241101-e2bb-4255-8caf-4136c566a962', ['timeout' => $budget]);
                    self::fail($name . ': expected an ApiTimeoutException');
                } catch (ApiTimeoutException) {
                    $elapsed = microtime(true) - $started;
                    self::assertGreaterThan($expected - 0.1, $elapsed, $name);
                    self::assertLessThan($expected + 0.2, $elapsed, $name);
                }
            }
        } finally {
            $server->stop();
        }
    }

    public function testTheSdkTimeoutAppliesPerAttempt(): void
    {
        \assert(self::$server !== null);
        $client = new ShieldLabs(['api_key' => Clients::API_KEY, 'base_url' => self::$server->url . '/slowapi', 'timeout' => 0.3, 'max_retries' => 0]);

        $this->expectException(ApiTimeoutException::class);
        $client->history->search(LookupType::Ip, '192.0.2.1');
    }
}
