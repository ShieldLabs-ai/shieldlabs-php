<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\MockHttpClient;

final class SingleUserIdTest extends TestCase
{
    public function testRepeatedIdsAreRejectedBeforeHttp(): void
    {
        foreach ([['first', 'second'], ['same', 'same'], [], '', 1] as $ids) {
            $http = new MockHttpClient();
            try {
                Clients::history($http)->history->list('user', ['query' => ['ids' => $ids]]);
                self::fail('Invalid ids accepted');
            } catch (ValidationException $error) {
                self::assertStringContainsString('ids', $error->getMessage());
            }
            self::assertSame([], $http->requests);
        }
    }

    public function testOneIdAndRepeatedBandsKeepTheirWireShape(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, ['data' => [], 'next_page_token' => null]));
        Clients::history($http)->history->list('user', ['query' => ['ids' => 'a+b% c', 'risk_bands' => ['trusted', 'dangerous']]]);
        $query = $http->lastRequest()->getUri()->getQuery();
        self::assertSame(1, substr_count($query, 'ids='));
        self::assertSame(2, substr_count($query, 'risk_bands='));
    }
}
