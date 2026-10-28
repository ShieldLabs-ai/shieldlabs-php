<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\MockHttpClient;

final class EntitiesTest extends TestCase
{
    private const DOMAIN = '00000000-0000-4000-8000-000000000031';

    public function testListOmitsIncludeTotal(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, [
            'data' => [$this->user()],
            'next_page_token' => null,
        ]));

        $page = Clients::history($http)->entities->list(self::DOMAIN, 'user', [
            'filter' => ['identification' => ['risk_bands' => ['dangerous']]],
        ]);

        self::assertSame('customer-42', $page->data[0]['id']);
        self::assertNull($page->nextPageToken);
        $uri = (string) $http->lastRequest()->getUri();
        self::assertStringContainsString('/api/v1/domains/' . self::DOMAIN . '/history/user', $uri);
        self::assertStringNotContainsString('include_total', $uri);
        self::assertStringContainsString('limit=20', $uri);
        self::assertStringContainsString('risk_bands', $uri);
    }

    public function testIncludeTotalIsRejected(): void
    {
        $http = new MockHttpClient();

        $this->expectException(ValidationException::class);
        Clients::history($http)->entities->list(self::DOMAIN, 'user', ['include_total' => true]);
    }

    public function testGetCard(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, ['data' => $this->user()]));

        $card = Clients::history($http)->entities->get(self::DOMAIN, 'user', 'customer-42');

        self::assertSame('customer-42', $card['id']);
        self::assertStringEndsWith('/history/user/customer-42', (string) $http->lastRequest()->getUri());
    }

    public function testMalformedRiskIsRejected(): void
    {
        $row = $this->user();
        $row['risk'] = ['state' => 'ok', 'band' => 'dangerous'];
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, [
            'data' => [$row],
            'next_page_token' => null,
        ]));

        $this->expectException(ApiException::class);
        Clients::history($http)->entities->list(self::DOMAIN, 'user');
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        return [
            'type' => 'user',
            'id' => 'customer-42',
            'domain_id' => self::DOMAIN,
            'domain' => 'example.com',
            'risk' => ['state' => 'unavailable', 'band' => null, 'as_of' => null],
            'signals' => ['state' => 'ok', 'items' => []],
        ];
    }
}
