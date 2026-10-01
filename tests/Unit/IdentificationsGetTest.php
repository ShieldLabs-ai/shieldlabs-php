<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ShieldLabs\Exception\ApiConnectionException;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ApiTimeoutException;
use ShieldLabs\Exception\AuthenticationException;
use ShieldLabs\Exception\BadRequestException;
use ShieldLabs\Exception\NotFoundException;
use ShieldLabs\Exception\QuotaExceededException;
use ShieldLabs\Exception\RateLimitException;
use ShieldLabs\Exception\ServerException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Http\NetworkException;
use ShieldLabs\LookupType;
use ShieldLabs\Resource\Identifications;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\FakeClock;
use ShieldLabs\Tests\Support\MockHttpClient;

final class IdentificationsGetTest extends TestCase
{
    private const REQUEST_ID = '3b241101-e2bb-4255-8caf-4136c566a962';

    /** Waits with the defaults when no row ever appears: the last one is cut short at the 10 s deadline. */
    private const DEFAULT_SLEEPS = [0.25, 0.5, 1.0, 1.5, 2.0, 2.0, 2.0, 0.75];

    private static function networkError(string $message, bool $timeout = false): NetworkException
    {
        return new NetworkException($message, new Request('GET', 'https://account.shieldlabs.ai'), $timeout);
    }

    private static function rateLimited(?string $retryAfter = null): ResponseInterface
    {
        return MockHttpClient::json(429, ['error' => 'too many requests'], $retryAfter === null ? [] : ['Retry-After' => $retryAfter]);
    }

    public function testReadsOnceWithoutWaiting(): void
    {
        $http = (new MockHttpClient())->queue(Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['wait' => false]);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertSame(
            'https://account.shieldlabs.ai/api/v1/history/request_id/' . self::REQUEST_ID . '?limit=1&offset=0',
            (string) $http->lastRequest()->getUri(),
        );
        self::assertSame([], $clock->sleeps);
    }

    public function testReturnsNullWithoutWaitingWhenThereIsNoRow(): void
    {
        $http = (new MockHttpClient())->queue(MockHttpClient::emptyPage());
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['wait' => false]));
        self::assertCount(1, $http->requests);
        self::assertSame([], $clock->sleeps);
    }

    public function testASingleLookupKeepsTheUsualRetries(): void
    {
        $http = (new MockHttpClient())->queue(MockHttpClient::json(503, ['error' => 'busy']), Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['wait' => false]);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertCount(2, $http->requests);
        self::assertCount(1, $clock->sleeps);
        self::assertGreaterThanOrEqual(0.25, $clock->sleeps[0]);
        self::assertLessThanOrEqual(0.5, $clock->sleeps[0]);
    }

    public function testSendsTheRequestIdInLowercase(): void
    {
        $http = (new MockHttpClient())->queue(Clients::rowFor(self::REQUEST_ID));

        Clients::history($http)->identifications->get(strtoupper(self::REQUEST_ID));

        self::assertStringContainsString('/request_id/' . self::REQUEST_ID . '?', (string) $http->lastRequest()->getUri());
    }

    public function testPollsWithTheBackoffScheduleUntilTheRowAppears(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::emptyPage(),
            MockHttpClient::emptyPage(),
            MockHttpClient::emptyPage(),
            MockHttpClient::emptyPage(),
            Clients::rowFor(self::REQUEST_ID),
        );
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertSame([0.25, 0.5, 1.0, 1.5], $clock->sleeps);
        self::assertCount(5, $http->requests);
    }

    public function testReturnsNullAfterTheLastPollAtTheDeadline(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID));

        self::assertSame(self::DEFAULT_SLEEPS, $clock->sleeps);
        self::assertEqualsWithDelta(10.0, $clock->elapsed(), 1e-9);
        self::assertCount(9, $http->requests);
    }

    public function testPollsOnlyOnceAtTheDeadlineWhenASleepEndsSlightlyEarly(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $clock = new FakeClock(wakesEarlyBy: 0.000001);

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID));

        self::assertCount(9, $http->requests);
        self::assertEqualsWithDelta(10.0, $clock->elapsed(), 0.0001);
    }

    /**
     * @return iterable<string, array{int|float, int|float, list<float>}>
     */
    public static function customSchedules(): iterable
    {
        // [poll_interval, timeout, sleeps]: the waits are p, 2p, 4p, 6p, 8p, then 8p, each at most
        // max(2 s, p), and the last one ends at the deadline.
        yield 'default 250 ms in 10 s' => [0.25, 10, [0.25, 0.5, 1.0, 1.5, 2.0, 2.0, 2.0, 0.75]];
        yield '1 s in 10 s' => [1, 10, [1.0, 2.0, 2.0, 2.0, 2.0, 1.0]];
        yield '3 s in 10 s, longer than 2 s and used as it is' => [3, 10, [3.0, 3.0, 3.0, 1.0]];
        yield '500 ms in 3 s' => [0.5, 3, [0.5, 1.0, 1.5]];
        yield '125 ms in 3 s' => [0.125, 3, [0.125, 0.25, 0.5, 0.75, 1.0, 0.375]];
        yield '3 s in 5 s' => [3, 5, [3.0, 2.0]];
    }

    /**
     * @param list<float> $sleeps
     */
    #[DataProvider('customSchedules')]
    public function testHonoursACustomTimeoutAndPollInterval(int|float $pollInterval, int|float $timeout, array $sleeps): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => $timeout, 'poll_interval' => $pollInterval]));

        self::assertSame($sleeps, $clock->sleeps);
        self::assertCount(\count($sleeps) + 1, $http->requests);
        self::assertEqualsWithDelta((float) $timeout, $clock->elapsed(), 1e-9);
    }

    /**
     * A budget that is not a sum of the waits: exactly one poll runs at the deadline,
     * and what it finds decides the result.
     *
     * @return iterable<string, array{ResponseInterface, ?string, ?class-string<ShieldLabsException>}>
     */
    public static function lastPolls(): iterable
    {
        yield 'nothing yet' => [MockHttpClient::emptyPage(), null, null];
        yield 'the row' => [Clients::rowFor(self::REQUEST_ID), self::REQUEST_ID, null];
        yield 'a server error' => [MockHttpClient::json(503, ['error' => 'busy']), null, ServerException::class];
    }

    /**
     * @param class-string<ShieldLabsException>|null $exception
     */
    #[DataProvider('lastPolls')]
    public function testTheLastPollRunsAtTheDeadline(ResponseInterface $last, ?string $requestId, ?string $exception): void
    {
        $empty = MockHttpClient::emptyPage();
        $http = (new MockHttpClient())->queue($empty, $empty, $empty, $empty, $last);
        $clock = new FakeClock();

        try {
            $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 2.3]);
            self::assertNull($exception, 'Expected ' . $exception);
            self::assertSame($requestId, $identification?->request_id);
        } catch (ShieldLabsException $thrown) {
            self::assertSame($exception, $thrown::class);
        }

        // Polls at 0, 0.25, 0.75 and 1.75 s, then the 1.5 s step is cut to the 0.55 s left.
        self::assertCount(5, $http->requests);
        self::assertCount(4, $clock->sleeps);
        self::assertSame([0.25, 0.5, 1.0], \array_slice($clock->sleeps, 0, 3));
        self::assertEqualsWithDelta(0.55, $clock->sleeps[3], 1e-9);
        self::assertEqualsWithDelta(2.3, $clock->elapsed(), 1e-9);
    }

    public function testPollsOnceWithAZeroTimeout(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 0]));
        self::assertCount(1, $http->requests);
        self::assertSame([], $clock->sleeps);
    }

    public function testThrowsTheErrorOfTheOnlyPollWithAZeroTimeout(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(503, ['error' => 'busy']));
        $clock = new FakeClock();

        $this->expectException(ServerException::class);
        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 0]);
        } finally {
            self::assertCount(1, $http->requests);
            self::assertSame([], $clock->sleeps);
        }
    }

    public function testKeepsPollingThroughTransientErrorsUntilTheRowAppears(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::json(503, ['error' => 'busy']),
            self::networkError('connection reset'),
            self::networkError('cURL error 28: timed out', true),
            self::rateLimited(),
            Clients::rowFor(self::REQUEST_ID),
        );
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        // The schedule goes on through the errors. After the 429 the 1.5 s step is already
        // longer than the 1 s minimum.
        self::assertSame([0.25, 0.5, 1.0, 1.5], $clock->sleeps);
        self::assertCount(5, $http->requests);
    }

    /**
     * Each answer carries the number of the poll, so a test can tell which error was thrown.
     *
     * @return iterable<string, array{\Closure(int): ResponseInterface, class-string<ShieldLabsException>}>
     */
    public static function transientErrors(): iterable
    {
        yield 'server error' => [
            static fn(int $poll): ResponseInterface => MockHttpClient::json(503, ['error' => 'busy on poll ' . $poll]),
            ServerException::class,
        ];
        yield 'edge proxy page' => [
            static fn(int $poll): ResponseInterface => MockHttpClient::text(502, '<html>bad gateway on poll ' . $poll . '</html>', 'text/html'),
            ServerException::class,
        ];
        yield 'connection error' => [
            static fn(int $poll): ResponseInterface => throw self::networkError('connection reset on poll ' . $poll),
            ApiConnectionException::class,
        ];
        yield 'timeout' => [
            static fn(int $poll): ResponseInterface => throw self::networkError('cURL error 28: timed out on poll ' . $poll, true),
            ApiTimeoutException::class,
        ];
    }

    /**
     * @param \Closure(int): ResponseInterface  $answer
     * @param class-string<ShieldLabsException> $exception
     */
    #[DataProvider('transientErrors')]
    public function testKeepsPollingAfterTransientErrorsAndThrowsTheLastOneAtTheDeadline(\Closure $answer, string $exception): void
    {
        $poll = 0;
        $http = (new MockHttpClient())->always(static function () use (&$poll, $answer): ResponseInterface {
            return $answer(++$poll);
        });
        $clock = new FakeClock();

        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);
            self::fail('Expected ' . $exception);
        } catch (ShieldLabsException $thrown) {
            self::assertInstanceOf($exception, $thrown);
            $text = $thrown instanceof ApiException ? $thrown->getRawBody() : $thrown->getMessage();
            self::assertStringContainsString('on poll 9', $text);
        }

        self::assertSame(self::DEFAULT_SLEEPS, $clock->sleeps);
        self::assertEqualsWithDelta(10.0, $clock->elapsed(), 1e-9);
        self::assertCount(9, $http->requests);
    }

    public function testThrowsTheLastRateLimitAtTheDeadline(): void
    {
        $http = (new MockHttpClient())->always(self::rateLimited());
        $clock = new FakeClock();

        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->getRetryAfter());
        }

        // At least 1 s after each 429, then the schedule; the last wait ends at the deadline.
        self::assertSame([1.0, 1.0, 1.0, 1.5, 2.0, 2.0, 1.5], $clock->sleeps);
        self::assertEqualsWithDelta(10.0, $clock->elapsed(), 1e-9);
        self::assertCount(8, $http->requests);
    }

    public function testReturnsNullWhenTheLastPollFindsNothingAfterErrors(): void
    {
        $busy = MockHttpClient::json(503, ['error' => 'busy']);
        $http = (new MockHttpClient())->queue($busy, $busy, $busy, $busy, $busy, $busy, $busy, $busy, MockHttpClient::emptyPage());
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID));

        self::assertSame(self::DEFAULT_SLEEPS, $clock->sleeps);
        self::assertCount(9, $http->requests);
    }

    public function testSendsOneRequestPerPollWhateverMaxRetries(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::json(503, ['error' => 'busy']),
            self::networkError('connection refused'),
            Clients::rowFor(self::REQUEST_ID),
        );
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock, ['max_retries' => 5])->identifications->get(self::REQUEST_ID);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertCount(3, $http->requests);
        self::assertSame([0.25, 0.5], $clock->sleeps);
    }

    public function testWaitsAtLeastOneSecondAfterARateLimitWithoutRetryAfter(): void
    {
        $http = (new MockHttpClient())->queue(self::rateLimited(), Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        self::assertNotNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID));
        self::assertSame([1.0], $clock->sleeps);
        self::assertCount(2, $http->requests);

        // A later step of the schedule is already longer than 1 s and stays as it is.
        $empty = MockHttpClient::emptyPage();
        $http = (new MockHttpClient())->queue($empty, $empty, $empty, self::rateLimited(), Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        self::assertNotNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID));
        self::assertSame([0.25, 0.5, 1.0, 1.5], $clock->sleeps);
    }

    /**
     * After a 429 the next poll waits the longest of the next step of the schedule,
     * 1 s and Retry-After (at most 10 s).
     *
     * @return iterable<string, array{string, int, list<float>}>
     */
    public static function waitsAfterARateLimit(): iterable
    {
        // [Retry-After, empty polls before the 429, sleeps]
        yield 'Retry-After 0' => ['0', 0, [1.0]];
        yield 'Retry-After as a date in the past' => ['Thu, 01 Jan 2015 00:00:00 GMT', 0, [1.0]];
        yield 'Retry-After under 1 s' => ['0.5', 0, [1.0]];
        yield 'Retry-After that cannot be read' => ['soon', 0, [1.0]];
        yield 'Retry-After longer than the step' => ['2', 1, [0.25, 2.0]];
        yield 'the 1.5 s step, longer than Retry-After' => ['1', 3, [0.25, 0.5, 1.0, 1.5]];
        yield 'the 1.5 s step, with Retry-After 0' => ['0', 3, [0.25, 0.5, 1.0, 1.5]];
    }

    /**
     * @param list<float> $sleeps
     */
    #[DataProvider('waitsAfterARateLimit')]
    public function testWaitsTheLongestOfTheStepOneSecondAndRetryAfterAfterARateLimit(string $retryAfter, int $emptyPolls, array $sleeps): void
    {
        $http = new MockHttpClient();
        for ($i = 0; $i < $emptyPolls; ++$i) {
            $http->queue(MockHttpClient::emptyPage());
        }
        $http->queue(self::rateLimited($retryAfter), Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertSame($sleeps, $clock->sleeps);
        self::assertCount($emptyPolls + 2, $http->requests);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function shortRetryAfters(): iterable
    {
        yield 'no Retry-After' => [null];
        yield 'Retry-After 0' => ['0'];
    }

    #[DataProvider('shortRetryAfters')]
    public function testCutsTheWaitAfterARateLimitShortAndPollsAtTheDeadline(?string $retryAfter): void
    {
        // With 0.625 s left, the 1 s wait after the 429 ends at the deadline, where the last poll runs.
        $http = (new MockHttpClient())->queue(self::rateLimited($retryAfter), Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 0.625]);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertSame([0.625], $clock->sleeps);
        self::assertCount(2, $http->requests);

        // When the poll at the deadline gets another 429, that 429 is thrown.
        $http = (new MockHttpClient())->always(self::rateLimited($retryAfter));
        $clock = new FakeClock();

        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 0.625]);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame($retryAfter === null ? null : 0.0, $exception->getRetryAfter());
        }
        self::assertSame([0.625], $clock->sleeps);
        self::assertCount(2, $http->requests);
    }

    public function testWaitsForRetryAfterBetweenPolls(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::emptyPage(),
            self::rateLimited('3'),
            self::rateLimited('3'),
            self::rateLimited('3'),
            Clients::rowFor(self::REQUEST_ID),
        );
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertSame([0.25, 3.0, 3.0, 3.0], $clock->sleeps);
        self::assertCount(5, $http->requests);
    }

    public function testWaitsForRetryAfterThatFitsAndStillPollsAtTheDeadline(): void
    {
        $empty = MockHttpClient::emptyPage();
        $http = (new MockHttpClient())->queue($empty, $empty, self::rateLimited('2'))->always($empty);
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 4]));

        self::assertSame([0.25, 0.5, 2.0, 1.25], $clock->sleeps);
        self::assertEqualsWithDelta(4.0, $clock->elapsed(), 1e-9);
        self::assertCount(5, $http->requests);
    }

    public function testTheWaitAfterARateLimitKeepsALadderStepLongerThanTwoSeconds(): void
    {
        // With a 3 s poll interval every step is 3 s: after a 429 the step is already longer than
        // the 1 s minimum and than Retry-After: 2, and a Retry-After of 5 s is longer still.
        $http = (new MockHttpClient())->queue(
            self::rateLimited(),
            self::rateLimited('2'),
            self::rateLimited('5'),
            Clients::rowFor(self::REQUEST_ID),
        );
        $clock = new FakeClock();

        $identification = Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 20, 'poll_interval' => 3]);

        self::assertSame(self::REQUEST_ID, $identification?->request_id);
        self::assertSame([3.0, 3.0, 5.0], $clock->sleeps);
        self::assertCount(4, $http->requests);
    }

    public function testCapsRetryAfterAtTenSeconds(): void
    {
        $http = (new MockHttpClient())->queue(self::rateLimited('60'), Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        self::assertNotNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 20]));
        self::assertSame([10.0], $clock->sleeps);
    }

    /**
     * @return iterable<string, array{string, int, list<float>}>
     */
    public static function retryAftersLongerThanTheTimeLeft(): iterable
    {
        // Polls at 0, 0.25 and 0.75 s find nothing; the 429 at 1.75 s leaves 8.25 s.
        yield 'longer than the time left' => ['9', 3, [0.25, 0.5, 1.0]];
        // After one empty poll 9.75 s are left, and 60 s is taken as 10 s.
        yield 'capped at 10 s, still longer' => ['60', 1, [0.25]];
    }

    /**
     * @param list<float> $sleeps
     */
    #[DataProvider('retryAftersLongerThanTheTimeLeft')]
    public function testThrowsARateLimitAtOnceWhenRetryAfterExceedsTheTimeLeft(string $retryAfter, int $emptyPolls, array $sleeps): void
    {
        $http = new MockHttpClient();
        for ($i = 0; $i < $emptyPolls; ++$i) {
            $http->queue(MockHttpClient::emptyPage());
        }
        $http->queue(self::rateLimited($retryAfter))->always(Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame((float) $retryAfter, $exception->getRetryAfter());
        }

        self::assertSame($sleeps, $clock->sleeps);
        self::assertCount($emptyPolls + 1, $http->requests);
    }

    public function testThrowsTheRateLimitWhenTimeRunsOut(): void
    {
        $http = (new MockHttpClient())->always(self::rateLimited('1'));
        $clock = new FakeClock();

        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 4]);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame(1.0, $exception->getRetryAfter());
        }
        self::assertSame([1.0, 1.0, 1.0, 1.0], $clock->sleeps);
        self::assertEqualsWithDelta(4.0, $clock->elapsed(), 1e-9);
        self::assertCount(5, $http->requests);
    }

    /**
     * @return iterable<string, array{float, list<float>}>
     */
    public static function attemptTimeouts(): iterable
    {
        // Time left before each poll: 10, 9.75, 9.25, 8.25, 6.75, 4.75, 2.75, 0.75 and 0 s.
        yield 'client timeout 10 s' => [10.0, [10.0, 9.75, 9.25, 8.25, 6.75, 4.75, 2.75, 1.0, 1.0]];
        yield 'client timeout 3 s' => [3.0, [3.0, 3.0, 3.0, 3.0, 3.0, 3.0, 2.75, 1.0, 1.0]];
        yield 'client timeout under 1 s' => [0.5, array_fill(0, 9, 0.5)];
    }

    /**
     * @param list<float> $expected
     */
    #[DataProvider('attemptTimeouts')]
    public function testLimitsEachPollToTheTimeLeftButAtLeastOneSecond(float $clientTimeout, array $expected): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $clock = new FakeClock();

        $client = Clients::history($http, $clock, ['http_client' => $http->timed($clientTimeout)]);

        self::assertNull($client->identifications->get(self::REQUEST_ID));
        self::assertSame($expected, $http->timeouts);
    }

    public function testLimitsTheOnlyPollOfAZeroTimeoutToOneSecond(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        $client = Clients::history($http, new FakeClock(), ['http_client' => $http->timed(10.0)]);

        self::assertNull($client->identifications->get(self::REQUEST_ID, ['timeout' => 0]));
        self::assertSame([1.0], $http->timeouts);
    }

    public function testSearchesAndSingleLookupsKeepTheClientTimeout(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $client = Clients::history($http, new FakeClock(), ['http_client' => $http->timed(10.0)]);

        $client->history->search(LookupType::RequestId, self::REQUEST_ID);
        $client->identifications->get(self::REQUEST_ID, ['wait' => false]);

        self::assertSame([10.0, 10.0], $http->timeouts);
    }

    /**
     * @return iterable<string, array{ResponseInterface, class-string<ShieldLabsException>}>
     */
    public static function errorsThatStopTheWait(): iterable
    {
        yield '400' => [MockHttpClient::json(400, ['error' => 'bad request']), BadRequestException::class];
        yield '401' => [MockHttpClient::text(401, "{\"error\":\"invalid api key\"}\n", 'text/plain; charset=utf-8'), AuthenticationException::class];
        yield '403' => [MockHttpClient::text(403, ''), AuthenticationException::class];
        yield '404' => [MockHttpClient::text(404, '404 page not found', 'text/plain'), NotFoundException::class];
        yield '402' => [MockHttpClient::json(402, ['error' => 'no requests left']), QuotaExceededException::class];
        yield 'another status' => [MockHttpClient::json(409, ['error' => 'conflict']), ApiException::class];
        yield 'a body that is not JSON' => [MockHttpClient::text(200, '<html>maintenance</html>', 'text/html'), ApiException::class];
    }

    /**
     * @param class-string<ShieldLabsException> $exception
     */
    #[DataProvider('errorsThatStopTheWait')]
    public function testStopsAtOnceOnOtherErrors(ResponseInterface $response, string $exception): void
    {
        $http = (new MockHttpClient())->queue($response)->always(Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);
            self::fail('Expected ' . $exception);
        } catch (ShieldLabsException $thrown) {
            self::assertSame($exception, $thrown::class);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $clock->sleeps);
    }

    public function testStopsAtOnceOnAuthenticationErrorsAfterEmptyPolls(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::emptyPage(),
            MockHttpClient::text(401, "{\"error\":\"invalid api key\"}\n", 'text/plain; charset=utf-8'),
        )->always(Clients::rowFor(self::REQUEST_ID));
        $clock = new FakeClock();

        $this->expectException(AuthenticationException::class);
        try {
            Clients::history($http, $clock)->identifications->get(self::REQUEST_ID);
        } finally {
            self::assertCount(2, $http->requests);
            self::assertSame([0.25], $clock->sleeps);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'not a UUID' => ['abc', []];
        yield 'empty' => ['', []];
        yield 'unknown option' => [self::REQUEST_ID, ['interval' => 1]];
        yield 'wait not bool' => [self::REQUEST_ID, ['wait' => 'yes']];
        yield 'negative timeout' => [self::REQUEST_ID, ['timeout' => -1]];
        yield 'timeout as string' => [self::REQUEST_ID, ['timeout' => '10']];
        yield 'zero poll interval' => [self::REQUEST_ID, ['poll_interval' => 0]];
        yield 'infinite timeout' => [self::REQUEST_ID, ['timeout' => \INF]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidCalls')]
    public function testValidatesBeforeSendingAnything(string $requestId, array $options): void
    {
        $http = new MockHttpClient();

        try {
            /** @phpstan-ignore argument.type */
            Clients::history($http)->identifications->get($requestId, $options);
            self::fail('Expected a ValidationException');
        } catch (ValidationException) {
            self::assertSame([], $http->requests);
        }
    }

    /**
     * @return iterable<string, array{float, list<float>}>
     */
    public static function ladders(): iterable
    {
        // Waits after the first eight polls for a poll interval p: p, 2p, 4p, 6p, 8p, then 8p, each at
        // most max(2 s, p).
        yield 'default 250 ms' => [0.25, [0.25, 0.5, 1.0, 1.5, 2.0, 2.0, 2.0, 2.0]];
        yield '125 ms' => [0.125, [0.125, 0.25, 0.5, 0.75, 1.0, 1.0, 1.0, 1.0]];
        yield '375 ms' => [0.375, [0.375, 0.75, 1.5, 2.0, 2.0, 2.0, 2.0, 2.0]];
        yield '1 s' => [1.0, [1.0, 2.0, 2.0, 2.0, 2.0, 2.0, 2.0, 2.0]];
        yield '2 s' => [2.0, [2.0, 2.0, 2.0, 2.0, 2.0, 2.0, 2.0, 2.0]];
        yield '2.5 s, its own cap' => [2.5, [2.5, 2.5, 2.5, 2.5, 2.5, 2.5, 2.5, 2.5]];
        yield '3 s, its own cap' => [3.0, [3.0, 3.0, 3.0, 3.0, 3.0, 3.0, 3.0, 3.0]];
    }

    /**
     * @param list<float> $expected
     */
    #[DataProvider('ladders')]
    public function testPollDelayFollowsTheScheduleForAnyPollInterval(float $pollInterval, array $expected): void
    {
        $delays = array_map(static fn(int $step): float => Identifications::pollDelay($step, $pollInterval), range(0, 7));

        self::assertSame($expected, $delays);
        self::assertSame($expected[0], Identifications::pollDelay(-1, $pollInterval));
    }

    public function testKeepsAPollIntervalBelow100MillisecondsAsItIs(): void
    {
        // No floor: 50 ms waits 50, 100, 200, 300 and 400 ms, then 400 ms again.
        $delays = array_map(static fn(int $step): float => Identifications::pollDelay($step, 0.05), range(0, 6));
        self::assertEqualsWithDelta([0.05, 0.1, 0.2, 0.3, 0.4, 0.4, 0.4], $delays, 1e-9);

        // The same ladder in a whole 2 s wait, whose last wait is cut short so the last poll runs at the deadline.
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $clock = new FakeClock();

        self::assertNull(Clients::history($http, $clock)->identifications->get(self::REQUEST_ID, ['timeout' => 2, 'poll_interval' => 0.05]));

        self::assertEqualsWithDelta([0.05, 0.1, 0.2, 0.3, 0.4, 0.4, 0.4, 0.15], $clock->sleeps, 1e-9);
        self::assertCount(9, $http->requests);
        self::assertEqualsWithDelta(2.0, $clock->elapsed(), 1e-9);
    }
}
