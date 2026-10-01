<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ShieldLabs\Exception\ApiConnectionException;
use ShieldLabs\Exception\ApiTimeoutException;
use ShieldLabs\Exception\RateLimitException;
use ShieldLabs\Exception\ServerException;
use ShieldLabs\Http\NetworkException;
use ShieldLabs\Internal\ResponseErrors;
use ShieldLabs\Internal\Transport;
use ShieldLabs\LookupType;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\FakeClock;
use ShieldLabs\Tests\Support\MockHttpClient;

final class RetryPolicyTest extends TestCase
{
    private const IP = '198.51.100.23';

    private static function request(): RequestInterface
    {
        return new Request('GET', 'https://account.shieldlabs.ai/api/v1/history/ip/198.51.100.23');
    }

    public function testBacksOffExponentiallyWithJitter(): void
    {
        $transport = new Transport(new MockHttpClient(), new Psr17Factory(), 'https://x', [], 2, true, new FakeClock());
        $error = new ServerException('boom', 500);

        for ($i = 0; $i < 200; ++$i) {
            foreach ([0 => 0.5, 1 => 1.0, 2 => 2.0, 3 => 4.0, 4 => 8.0, 5 => 8.0, 9 => 8.0] as $attempt => $cap) {
                $delay = $transport->retryDelay($attempt, $error);
                self::assertGreaterThanOrEqual($cap / 2, $delay, "attempt $attempt");
                self::assertLessThanOrEqual($cap, $delay, "attempt $attempt");
            }
        }
    }

    public function testHonoursRetryAfterUpToTenSeconds(): void
    {
        $transport = new Transport(new MockHttpClient(), new Psr17Factory(), 'https://x', [], 2, true, new FakeClock());

        self::assertSame(2.0, $transport->retryDelay(0, new RateLimitException('x', 429, retryAfter: 2.0)));
        self::assertSame(10.0, $transport->retryDelay(0, new RateLimitException('x', 429, retryAfter: 60.0)));
        self::assertSame(0.0, $transport->retryDelay(3, new RateLimitException('x', 429, retryAfter: 0.0)));
        self::assertSame(7.0, $transport->retryDelay(0, new ServerException('x', 503, headers: ['retry-after' => ['7']])));
    }

    public function testWaitsAtLeastOneSecondBeforeRetryingARateLimitWithoutRetryAfter(): void
    {
        $transport = new Transport(new MockHttpClient(), new Psr17Factory(), 'https://x', [], 2, true, new FakeClock());
        $error = new RateLimitException('x', 429);

        for ($i = 0; $i < 200; ++$i) {
            self::assertSame(1.0, $transport->retryDelay(0, $error));
            self::assertSame(1.0, $transport->retryDelay(1, $error));
            $delay = $transport->retryDelay(2, $error);
            self::assertGreaterThanOrEqual(1.0, $delay);
            self::assertLessThanOrEqual(2.0, $delay);
        }

        $http = (new MockHttpClient())->queue(MockHttpClient::json(429, ['error' => 'too many requests']), MockHttpClient::emptyPage());
        $clock = new FakeClock();

        Clients::history($http, $clock)->history->search(LookupType::Ip, self::IP);

        self::assertSame([1.0], $clock->sleeps);
        self::assertCount(2, $http->requests);
    }

    public function testRetriesServerErrorsThenSucceeds(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::text(502, '<html>bad gateway</html>', 'text/html'),
            MockHttpClient::json(503, ['error' => 'server is busy']),
            MockHttpClient::emptyPage(),
        );
        $clock = new FakeClock();

        $page = Clients::history($http, $clock)->history->search(LookupType::Ip, self::IP);

        self::assertSame(0, $page->total);
        self::assertCount(3, $http->requests);
        self::assertCount(2, $clock->sleeps);
    }

    /**
     * Outside the wait for a verdict, Retry-After is followed as sent: no 1 s minimum.
     *
     * @return iterable<string, array{string, float}>
     */
    public static function retryAfters(): iterable
    {
        yield '1 s' => ['1', 1.0];
        yield '3 s' => ['3', 3.0];
        yield 'under 1 s' => ['0.5', 0.5];
        yield '0, retried at once' => ['0', 0.0];
        yield 'a date in the past, retried at once' => ['Thu, 01 Jan 2015 00:00:00 GMT', 0.0];
        yield 'capped at 10 s' => ['60', 10.0];
    }

    #[DataProvider('retryAfters')]
    public function testRetriesHistoryRateLimitsFollowingRetryAfterAsSent(string $retryAfter, float $sleep): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::json(429, ['error' => 'too many requests'], ['Retry-After' => $retryAfter]),
            MockHttpClient::emptyPage(),
        );
        $clock = new FakeClock();

        Clients::history($http, $clock)->history->search(LookupType::Ip, self::IP);

        self::assertSame([$sleep], $clock->sleeps);
        self::assertCount(2, $http->requests);
    }

    public function testRetryAfterAsAnHttpDate(): void
    {
        self::assertSame(30.0, ResponseErrors::retryAfter('Wed, 30 Sep 2026 12:00:30 GMT', (int) strtotime('2026-09-30 12:00:00 UTC')));
        self::assertSame(0.0, ResponseErrors::retryAfter('Wed, 30 Sep 2026 11:00:00 GMT', (int) strtotime('2026-09-30 12:00:00 UTC')));
        self::assertSame(1.5, ResponseErrors::retryAfter(' 1.5 '));
        self::assertNull(ResponseErrors::retryAfter('soon'));
        self::assertNull(ResponseErrors::retryAfter(null));
    }

    public function testMaxRetriesZeroSendsOneRequest(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(500, ''));
        $clock = new FakeClock();

        $this->expectException(ServerException::class);
        try {
            Clients::history($http, $clock, ['max_retries' => 0])->history->search(LookupType::Ip, self::IP);
        } finally {
            self::assertCount(1, $http->requests);
            self::assertSame([], $clock->sleeps);
        }
    }

    public function testCustomMaxRetries(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(500, ''));

        try {
            Clients::history($http, null, ['max_retries' => 4])->history->search(LookupType::Ip, self::IP);
            self::fail('Expected a ServerException');
        } catch (ServerException) {
            self::assertCount(5, $http->requests);
        }
    }

    public function testMapsNetworkFailuresAndRetriesThem(): void
    {
        $http = (new MockHttpClient())->always(new NetworkException('cURL error 7: Failed to connect', self::request()));

        try {
            Clients::history($http)->history->search(LookupType::Ip, self::IP);
            self::fail('Expected an ApiConnectionException');
        } catch (ApiConnectionException $exception) {
            self::assertStringContainsString('Could not reach the ShieldLabs API', $exception->getMessage());
            self::assertInstanceOf(NetworkException::class, $exception->getPrevious());
        }
        self::assertCount(3, $http->requests);
    }

    public function testMapsTimeoutsAndRetriesThem(): void
    {
        $http = (new MockHttpClient())->always(new NetworkException('cURL error 28: timed out', self::request(), true));

        try {
            Clients::history($http)->history->search(LookupType::Ip, self::IP);
            self::fail('Expected an ApiTimeoutException');
        } catch (ApiTimeoutException $exception) {
            self::assertStringContainsString('did not answer in time', $exception->getMessage());
        }
        self::assertCount(3, $http->requests);
    }

    public function testRecognisesTimeoutsFromOtherPsr18Clients(): void
    {
        $foreign = new class ('cURL error 28: Operation timed out after 10001 milliseconds', self::request()) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
        $http = (new MockHttpClient())->always($foreign);

        $this->expectException(ApiTimeoutException::class);
        Clients::history($http, null, ['max_retries' => 0])->history->search(LookupType::Ip, self::IP);
    }

    public function testGenericClientExceptionsAreConnectionErrors(): void
    {
        $generic = new class ('socket closed') extends \RuntimeException implements ClientExceptionInterface {};
        $idle = new class ('Idle timeout reached') extends \RuntimeException implements ClientExceptionInterface {};
        $http = (new MockHttpClient())->queue($generic, $idle, MockHttpClient::emptyPage());

        self::assertSame(0, Clients::history($http)->history->search(LookupType::Ip, self::IP)->total);
        self::assertCount(3, $http->requests);
    }

    public function testDoesNotRetryRequestsTheClientCannotSend(): void
    {
        $invalid = new class ('invalid request', self::request()) extends \InvalidArgumentException implements RequestExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
        $http = (new MockHttpClient())->always($invalid);

        try {
            Clients::history($http)->history->search(LookupType::Ip, self::IP);
            self::fail('Expected an ApiConnectionException');
        } catch (ApiConnectionException $exception) {
            self::assertStringContainsString('could not send the request', $exception->getMessage());
        }
        self::assertCount(1, $http->requests);
    }

    /**
     * A response whose body fails to read, the way a streamed body behaves when the
     * connection stalls or drops after the headers arrived.
     */
    private function unreadable(int $status, string $message): ResponseInterface
    {
        $body = self::createStub(StreamInterface::class);
        $body->method('__toString')->willThrowException(new \RuntimeException($message));
        $body->method('getContents')->willThrowException(new \RuntimeException($message));

        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    public function testTreatsATimeoutWhileReadingTheBodyAsATimeout(): void
    {
        $http = (new MockHttpClient())->queue(
            $this->unreadable(200, 'Unable to read stream contents: Idle timeout reached for "https://account.shieldlabs.ai/api/v1/history/ip/198.51.100.23".'),
            $this->unreadable(200, 'Unable to read stream contents: Max duration was reached for "https://account.shieldlabs.ai/api/v1/history/ip/198.51.100.23".'),
            MockHttpClient::emptyPage(),
        );
        $clock = new FakeClock();

        self::assertSame(0, Clients::history($http, $clock)->history->search(LookupType::Ip, self::IP)->total);
        self::assertCount(3, $http->requests);
        self::assertCount(2, $clock->sleeps);

        $http = (new MockHttpClient())->always($this->unreadable(200, 'Unable to read stream contents: Idle timeout reached'));
        try {
            Clients::history($http, null, ['max_retries' => 0])->history->search(LookupType::Ip, self::IP);
            self::fail('Expected an ApiTimeoutException');
        } catch (ApiTimeoutException $exception) {
            self::assertStringContainsString('did not answer in time', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testTreatsAnyOtherBodyReadFailureAsAConnectionError(): void
    {
        $http = (new MockHttpClient())->queue(
            $this->unreadable(401, 'Unable to read stream contents: Connection reset by peer'),
            MockHttpClient::emptyPage(),
        );
        $clock = new FakeClock();

        self::assertSame(0, Clients::history($http, $clock)->history->search(LookupType::Ip, self::IP)->total);
        self::assertCount(2, $http->requests, 'retried like other transport failures');

        $http = (new MockHttpClient())->always($this->unreadable(200, 'Unable to read stream contents: Connection reset by peer'));
        try {
            Clients::management($http, null, ['max_retries' => 0])->getProfile();
            self::fail('Expected an ApiConnectionException');
        } catch (ApiConnectionException $exception) {
            self::assertStringContainsString('could not be read', $exception->getMessage());
        }
    }

    public function testReadsAStreamedBodyOnlyOnce(): void
    {
        // Like a network stream, a socket cannot rewind: a second read returns nothing.
        $once = static function (int $status, string $json): ResponseInterface {
            $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            \assert($pair !== false);
            fwrite($pair[0], $json);
            fclose($pair[0]);

            return new Response($status, ['Content-Type' => 'application/json'], Stream::create($pair[1]));
        };
        $http = (new MockHttpClient())->queue(
            $once(429, '{"error":"too many requests"}'),
            $once(200, '{"data":[],"total":7}'),
        );

        try {
            Clients::history($http, null, ['max_retries' => 0])->history->search(LookupType::Ip, self::IP);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame('too many requests', $exception->getErrorMessage());
        }
        self::assertSame(7, Clients::history($http)->history->search(LookupType::Ip, self::IP)->total);
    }

    public function testManagementNeverRetriesRateLimits(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(429, ['error' => 'too many requests'], ['Retry-After' => '1']));
        $clock = new FakeClock();

        $this->expectException(RateLimitException::class);
        try {
            Clients::management($http, $clock, ['max_retries' => 5])->getProfile();
        } finally {
            self::assertCount(1, $http->requests);
            self::assertSame([], $clock->sleeps);
        }
    }

    public function testManagementRetriesServerErrors(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::json(503, ['error' => 'server is busy']),
            MockHttpClient::json(200, ['Domain' => 'example.com', 'Weight' => -5, 'CreatedAt' => '0001-01-01T00:00:00Z']),
        );

        $profile = Clients::management($http)->getProfile();

        self::assertSame(-5, $profile->remaining_identifications);
        self::assertSame('0001-01-01T00:00:00.000Z', $profile->toArray()['created_at']);
        self::assertCount(2, $http->requests);
    }
}
