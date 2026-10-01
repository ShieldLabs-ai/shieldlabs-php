<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\QuotaExceededException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\ShieldLabsManagement;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\ErrorLog;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Tests\Support\MockHttpClient;

final class ManagementClientTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['SHIELDLABS_SECRET_KEY', 'SHIELDLABS_DOMAIN', 'SHIELDLABS_MANAGEMENT_BASE_URL'] as $name) {
            putenv($name);
        }
    }

    public function testReadsTheProfile(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, Fixtures::json('management-profile.json')));
        $client = Clients::management($http, null, ['domain' => 'https://www.Example.com/login']);

        $profile = $client->getProfile();

        self::assertSame(Fixtures::json('management-profile-expected.json'), $profile->toArray());
        self::assertSame($profile->toArray(), $profile->jsonSerialize());
        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.shieldlabs.ai/v1/profile', (string) $request->getUri());
        self::assertSame('example.com', $request->getHeaderLine('X-Shield-Domain'));
        self::assertSame('Bearer ' . Clients::SECRET_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringStartsWith('shieldlabs-php/1.0.0 ', $request->getHeaderLine('User-Agent'));
        self::assertSame('example.com', $client->getDomain());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function domains(): iterable
    {
        yield 'plain' => ['example.com', 'example.com'];
        yield 'uppercase' => ['Example.COM', 'example.com'];
        yield 'spaces' => ["  example.com \n", 'example.com'];
        yield 'https scheme' => ['https://example.com', 'example.com'];
        yield 'http scheme and path' => ['http://example.com/signup?x=1', 'example.com'];
        yield 'trailing slash' => ['example.com/', 'example.com'];
        yield 'leading www' => ['www.example.com', 'example.com'];
        yield 'everything' => [' HTTPS://WWW.Example.com/path/ ', 'example.com'];
        yield 'subdomain kept' => ['shop.example.com', 'shop.example.com'];
        yield 'www inside kept' => ['app.www.example.com', 'app.www.example.com'];
        yield 'protocol relative' => ['//example.com/', 'example.com'];
        yield 'fragment' => ['example.com#top', 'example.com'];
    }

    #[DataProvider('domains')]
    public function testNormalizesTheDomain(string $input, string $expected): void
    {
        self::assertSame($expected, ShieldLabsManagement::normalizeDomain($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'scheme only' => ['https://'];
        yield 'inner space' => ['exa mple.com'];
        yield 'line break' => ["example.com\nX-Injected: 1"];
        yield 'NUL byte' => ["exam\0ple.com"];
        yield 'control character' => ["exam\x01ple.com"];
        yield 'not ASCII' => ['bücher.example'];
    }

    #[DataProvider('unusableDomains')]
    public function testRejectsUnusableDomains(string $domain): void
    {
        $http = new MockHttpClient();

        try {
            ShieldLabsManagement::normalizeDomain($domain);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $exception) {
            if (trim($domain) !== '') {
                self::assertStringNotContainsString($domain, $exception->getMessage(), 'the value is not repeated');
            }
        }
        $this->expectException(ValidationException::class);
        Clients::management($http, null, ['domain' => $domain]);
    }

    public function testAsksForThePunycodeFormOfAnInternationalDomain(): void
    {
        self::assertSame('xn--bcher-kva.example', ShieldLabsManagement::normalizeDomain('https://www.XN--BCHER-KVA.example/'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('punycode');
        ShieldLabsManagement::normalizeDomain('bücher.example');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretsThatCannotBeSent(): iterable
    {
        yield 'line feed' => ["0123456789abcdef\nX-Injected: 1"];
        yield 'carriage return' => ["0123456789abcdef\r0123456789abcdef"];
        yield 'NUL byte' => ["0123456789abcdef\x000123456789abcdef"];
        yield 'inner space' => ['0123456789abcdef 0123456789abcdef'];
    }

    #[DataProvider('secretsThatCannotBeSent')]
    public function testRejectsASecretKeyThatCannotBeSentInAHeader(string $secret): void
    {
        $http = new MockHttpClient();

        try {
            Clients::management($http, null, ['secret_key' => $secret]);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('The Secret Key contains characters that cannot be sent in an HTTP header', $exception->getMessage());
            self::assertStringNotContainsString('0123456789abcdef', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame([], $http->requests);
    }

    public function testRequiresASecretKey(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Secret Key is required');
        new ShieldLabsManagement(['secret_key' => '  ', 'domain' => 'example.com', 'http_client' => new MockHttpClient()]);
    }

    public function testRequiresADomain(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('domain is required');
        new ShieldLabsManagement(['secret_key' => Clients::SECRET_KEY, 'http_client' => new MockHttpClient()]);
    }

    public function testReadsCredentialsAndBaseUrlFromTheEnvironment(): void
    {
        putenv('SHIELDLABS_SECRET_KEY=' . Clients::SECRET_KEY);
        putenv('SHIELDLABS_DOMAIN=WWW.example.org');
        putenv('SHIELDLABS_MANAGEMENT_BASE_URL=https://dev.api.shieldlabs.ai/v1/');
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, Fixtures::json('management-profile.json')));

        (new ShieldLabsManagement(['http_client' => $http]))->getProfile();

        $request = $http->lastRequest();
        self::assertSame('https://dev.api.shieldlabs.ai/v1/profile', (string) $request->getUri());
        self::assertSame('example.org', $request->getHeaderLine('X-Shield-Domain'));
        self::assertSame('Bearer ' . Clients::SECRET_KEY, $request->getHeaderLine('Authorization'));
    }

    public function testRefusesPlainHttpOutsideLoopbackHosts(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, Fixtures::json('management-profile.json')));

        Clients::management($http, null, ['base_url' => 'http://localhost:8081/v1'])->getProfile();
        self::assertSame('http://localhost:8081/v1/profile', (string) $http->lastRequest()->getUri());

        try {
            Clients::management($http, null, ['base_url' => 'http://api.shieldlabs.ai']);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('must be an https URL', $exception->getMessage());
        }

        putenv('SHIELDLABS_MANAGEMENT_BASE_URL=http://api.shieldlabs.ai');
        $this->expectException(ValidationException::class);
        Clients::management($http);
    }

    public function testAcceptsPlainHttpToAnotherHostWhenAskedTo(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, Fixtures::json('management-profile.json')));

        $log = ErrorLog::during(static function () use ($http): void {
            Clients::management($http, null, ['base_url' => 'http://management-mock:8081', 'allow_insecure_http' => true])->getProfile();
        });

        self::assertSame('http://management-mock:8081/v1/profile', (string) $http->lastRequest()->getUri());
        self::assertCount(1, $log->warnings());
        self::assertStringNotContainsString(Clients::SECRET_KEY, $log->contents);
    }

    public function testMapsQuotaExceeded(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(402, ''));

        $this->expectException(QuotaExceededException::class);
        Clients::management($http)->getProfile();
    }

    public function testRejectsAnUnexpectedProfileBody(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, null));

        $this->expectException(ApiException::class);
        Clients::management($http)->getProfile();
    }

    public function testToleratesAPartialProfile(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, ['Weight' => 12.0, 'CreatedAt' => 'yesterday']));

        $profile = Clients::management($http)->getProfile();

        self::assertSame('', $profile->domain);
        self::assertSame(12, $profile->remaining_identifications);
        self::assertNull($profile->created_at);
        self::assertSame('', $profile->public_key_masked);
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown option "api_key"');
        /** @phpstan-ignore argument.type */
        new ShieldLabsManagement(['api_key' => 'sec_x', 'secret_key' => 'x', 'domain' => 'example.com']);
    }
}
