<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\RateLimitException;
use ShieldLabs\LookupType;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\FakeClock;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Tests\Support\MockHttpClient;

/**
 * error-responses.json: status and body to exception class, and whether the SDK retries.
 */
final class ErrorResponsesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, ?string, string, string, bool}>
     */
    public static function cases(): iterable
    {
        foreach (Fixtures::json('error-responses.json')['cases'] as $index => $case) {
            \assert(\is_array($case) && \is_string($case['surface']) && \is_int($case['status']));
            \assert(\is_string($case['body']) && \is_string($case['expected_error']) && \is_bool($case['retry']));
            \assert($case['content_type'] === null || \is_string($case['content_type']));

            yield \sprintf('%d %s %d', $index, $case['surface'], $case['status']) => [
                $case['surface'],
                $case['status'],
                $case['content_type'],
                $case['body'],
                $case['expected_error'],
                $case['retry'],
            ];
        }
    }

    #[DataProvider('cases')]
    public function testMapsTheResponseAndRetriesOnlyWhenExpected(
        string $surface,
        int $status,
        ?string $contentType,
        string $body,
        string $expectedError,
        bool $retry,
    ): void {
        $http = (new MockHttpClient())->always(MockHttpClient::text($status, $body, $contentType));
        $clock = new FakeClock();
        $expectedClass = 'ShieldLabs\\Exception\\' . preg_replace('/Error$/', 'Exception', $expectedError);

        try {
            if ($surface === 'history') {
                Clients::history($http, $clock)->history->search(LookupType::RequestId, '3b241101-e2bb-4255-8caf-4136c566a962');
            } else {
                Clients::management($http, $clock)->getProfile();
            }
            self::fail('Expected ' . $expectedClass);
        } catch (ApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame($body, $exception->getRawBody());
            self::assertStringContainsString('HTTP ' . $status, $exception->getMessage());
        }

        self::assertCount($retry ? 3 : 1, $http->requests, 'requests sent (1 + 2 retries when retried)');
        self::assertCount($retry ? 2 : 0, $clock->sleeps);
    }

    public function testParsesJsonSentAsPlainText(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(401, "{\"error\":\"invalid api key\"}\n", 'text/plain; charset=utf-8'));

        try {
            Clients::history($http)->history->search(LookupType::DeviceId, 'ac7c303d-971b-41d1-8e25-cd5b46b46aed');
            self::fail('Expected an exception');
        } catch (ApiException $exception) {
            self::assertSame('invalid api key', $exception->getErrorMessage());
            self::assertSame(['error' => 'invalid api key'], $exception->getBody());
            self::assertSame('ShieldLabs API error (HTTP 401): invalid api key', $exception->getMessage());
            self::assertSame('text/plain; charset=utf-8', $exception->getHeader('Content-Type'));
            self::assertSame(['text/plain; charset=utf-8'], $exception->getHeaders()['content-type']);
        }
    }

    /**
     * @return iterable<string, array{string, ?string, mixed, ?string}>
     */
    public static function bodies(): iterable
    {
        yield 'empty' => ['', null, '', null];
        yield 'null' => ['null', 'application/json', null, null];
        yield 'bare string' => ['"fail parse uuid"', 'application/json', 'fail parse uuid', 'fail parse uuid'];
        yield 'object with message' => ['{"message":"nope"}', 'application/json', ['message' => 'nope'], 'nope'];
        yield 'object without text' => ['{"code":7}', 'application/json', ['code' => 7], null];
        yield 'plain text' => ['404 page not found', 'text/plain', '404 page not found', '404 page not found'];
        yield 'html' => ['<html><body>Bad Gateway</body></html>', 'text/html', '<html><body>Bad Gateway</body></html>', null];
        yield 'number' => ['42', 'application/json', 42, null];
    }

    #[DataProvider('bodies')]
    public function testParsesErrorBodiesDefensively(string $raw, ?string $contentType, mixed $body, ?string $message): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(400, $raw, $contentType));

        try {
            Clients::management($http)->getProfile();
            self::fail('Expected an exception');
        } catch (ApiException $exception) {
            self::assertSame($body, $exception->getBody());
            self::assertSame($message, $exception->getErrorMessage());
        }
    }

    public function testTruncatesVeryLongServerMessages(): void
    {
        $long = str_repeat('x', 800);
        $http = (new MockHttpClient())->always(MockHttpClient::json(400, ['error' => $long]));

        try {
            Clients::management($http)->getProfile();
            self::fail('Expected an exception');
        } catch (ApiException $exception) {
            self::assertSame(503, \strlen((string) $exception->getErrorMessage()));
        }
    }

    public function testReadsRetryAfterOnRateLimits(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(429, ['error' => 'too many requests'], ['Retry-After' => '3']));

        try {
            Clients::management($http)->getProfile();
            self::fail('Expected an exception');
        } catch (RateLimitException $exception) {
            self::assertSame(3.0, $exception->getRetryAfter());
        }
        self::assertCount(1, $http->requests);
    }

    public function testUsesTheBaseClassForOtherStatuses(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(409, 'conflict'));

        try {
            Clients::management($http)->getProfile();
            self::fail('Expected an exception');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(409, $exception->getCode());
        }
        self::assertCount(1, $http->requests);
    }

    public function testMapsForbiddenToAuthentication(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(403, ''));

        $this->expectException(\ShieldLabs\Exception\AuthenticationException::class);
        Clients::management($http)->getProfile();
    }
}
