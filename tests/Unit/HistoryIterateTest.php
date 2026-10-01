<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ShieldLabs\Exception\ServerException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\LookupType;
use ShieldLabs\Model\Identification;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\MockHttpClient;

final class HistoryIterateTest extends TestCase
{
    /**
     * @param list<string> $requestIds
     */
    private static function page(array $requestIds, int $total): ResponseInterface
    {
        $rows = array_map(static fn(string $id): array => [
            'request_id' => $id,
            'device_id' => 'd8e0f2a4-b6c8-4d0e-bf2a-4b6c8d0e2f4a',
            'score' => 10,
            'created_at' => '2026-09-30 12:00:00.000',
        ], $requestIds);

        return MockHttpClient::json(200, ['data' => $rows, 'total' => $total]);
    }

    private static function id(int $n): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    /**
     * @param iterable<Identification> $items
     *
     * @return list<string>
     */
    private static function ids(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->request_id;
        }

        return $ids;
    }

    /**
     * @return list<array{string, string}> [limit, offset] of each request
     */
    private static function paging(MockHttpClient $http): array
    {
        return array_map(static function (RequestInterface $request): array {
            parse_str($request->getUri()->getQuery(), $query);

            return [(string) ($query['limit'] ?? ''), (string) ($query['offset'] ?? '')];
        }, $http->requests);
    }

    public function testWalksPagesUntilTheTotal(): void
    {
        $http = (new MockHttpClient())->queue(
            self::page([self::id(1), self::id(2)], 5),
            self::page([self::id(3), self::id(4)], 5),
            self::page([self::id(5)], 5),
        );

        $ids = self::ids(Clients::history($http)->history->iterate(LookupType::DeviceId, 'd8e0f2a4-b6c8-4d0e-bf2a-4b6c8d0e2f4a', ['page_size' => 2]));

        self::assertSame([self::id(1), self::id(2), self::id(3), self::id(4), self::id(5)], $ids);
        self::assertSame([['2', '0'], ['2', '2'], ['2', '4']], self::paging($http));
    }

    public function testDeduplicatesRowsRepeatedAcrossPages(): void
    {
        // A new row arrived between the two requests, so offset 2 repeats row 2.
        $http = (new MockHttpClient())->queue(
            self::page([self::id(1), self::id(2)], 4),
            self::page([self::id(2), self::id(3)], 5),
            self::page([self::id(4)], 5),
        );

        $ids = self::ids(Clients::history($http)->history->iterate(LookupType::UserHid, 'user-7', ['page_size' => 2]));

        self::assertSame([self::id(1), self::id(2), self::id(3), self::id(4)], $ids);
        self::assertCount(3, $http->requests);
    }

    public function testStopsAtAnEmptyPage(): void
    {
        $http = (new MockHttpClient())->queue(
            self::page([self::id(1), self::id(2)], 10),
            self::page([], 10),
        );

        $ids = self::ids(Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7', ['page_size' => 2]));

        self::assertSame([self::id(1), self::id(2)], $ids);
        self::assertCount(2, $http->requests);
    }

    public function testStopsWhenTheFirstPageIsEmpty(): void
    {
        $http = (new MockHttpClient())->queue(MockHttpClient::emptyPage());

        self::assertSame([], self::ids(Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7')));
        self::assertSame([['100', '0']], self::paging($http));
    }

    public function testStopsAfterMaxItems(): void
    {
        $http = (new MockHttpClient())->queue(
            self::page([self::id(1), self::id(2), self::id(3)], 9),
            self::page([self::id(4), self::id(5), self::id(6)], 9),
        );

        $ids = self::ids(Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7', ['page_size' => 3, 'max_items' => 4]));

        self::assertSame([self::id(1), self::id(2), self::id(3), self::id(4)], $ids);
        self::assertCount(2, $http->requests);
    }

    public function testYieldsNothingWithMaxItemsZero(): void
    {
        $http = (new MockHttpClient())->always(self::page([self::id(1), self::id(2)], 2));

        $ids = self::ids(Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7', ['max_items' => 0]));

        self::assertSame([], $ids);
        self::assertSame([], $http->requests);
    }

    public function testANullMaxItemsMeansNoLimit(): void
    {
        $http = (new MockHttpClient())->queue(
            self::page([self::id(1), self::id(2)], 3),
            self::page([self::id(3)], 3),
        );

        $ids = self::ids(Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7', ['page_size' => 2, 'max_items' => null]));

        self::assertSame([self::id(1), self::id(2), self::id(3)], $ids);
        self::assertCount(2, $http->requests);
    }

    public function testIsLazy(): void
    {
        $http = (new MockHttpClient())->queue(self::page([self::id(1)], 1));

        $generator = Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7');
        self::assertSame([], $http->requests);

        self::assertSame(self::id(1), $generator->current()?->request_id);
        self::assertCount(1, $http->requests);
    }

    public function testKeysAreSequentialSoIteratorToArrayKeepsEveryRow(): void
    {
        $http = (new MockHttpClient())->queue(
            self::page([self::id(1), self::id(2)], 3),
            self::page([self::id(3)], 3),
        );

        $items = iterator_to_array(Clients::history($http)->history->iterate(LookupType::Ip, '198.51.100.7', ['page_size' => 2]));

        self::assertSame([0, 1, 2], array_keys($items));
    }

    public function testValidatesBeforeIterating(): void
    {
        $http = new MockHttpClient();
        $client = Clients::history($http);

        foreach ([
            [LookupType::DeviceId, 'not-a-uuid', []],
            ['browser', 'x', []],
            [LookupType::Ip, '198.51.100.7', ['page_size' => 0]],
            [LookupType::Ip, '198.51.100.7', ['page_size' => 101]],
            [LookupType::Ip, '198.51.100.7', ['max_items' => -1]],
            [LookupType::Ip, '198.51.100.7', ['max_items' => '5']],
            [LookupType::Ip, '198.51.100.7', ['limit' => 10]],
            [LookupType::UserHid, 'a/b', []],
            [LookupType::UserHid, '..', []],
        ] as [$type, $value, $options]) {
            try {
                /** @phpstan-ignore argument.type */
                $client->history->iterate($type, $value, $options);
                self::fail('Expected a ValidationException for ' . json_encode([$value, $options]));
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $http->requests);
    }

    public function testPropagatesErrorsFromLaterPages(): void
    {
        $http = (new MockHttpClient())->queue(self::page([self::id(1)], 3))->always(MockHttpClient::text(500, ''));
        $generator = Clients::history($http, null, ['max_retries' => 0])->history->iterate(LookupType::Ip, '198.51.100.7', ['page_size' => 1]);

        self::assertSame(self::id(1), $generator->current()?->request_id);
        $this->expectException(ServerException::class);
        $generator->next();
    }
}
