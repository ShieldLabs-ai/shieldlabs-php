<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Model\EntityPage;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\MockHttpClient;

final class EntityResponsesTest extends TestCase
{
    private const ID = '33333333-3333-4333-8333-333333333333';

    /** @return array<string, mixed> */
    private static function card(): array
    {
        return ['type' => 'identification', 'id' => self::ID,
            'created_at' => '2026-10-29T09:00:00.123Z',
            'risk' => ['state' => 'ok', 'score' => 30, 'band' => 'suspicious'],
            'signals' => ['state' => 'ok', 'items' => ['vpn', 'rate_limited']],
            'public_ip' => '203.0.113.24', 'traffic' => ['channel' => 'paid', 'utm_source' => 'google']];
    }

    public function testGetReadsCurrentCardWithoutLosingIdOrScore(): void
    {
        foreach ([0, 30, 60, 100] as $score) {
            $card = self::card();
            $card['risk'] = ['state' => 'ok', 'score' => $score];
            $http = (new MockHttpClient())->always(MockHttpClient::json(200, ['data' => $card]));
            $result = Clients::history($http)->identifications->get(self::ID, ['wait' => false]);
            self::assertNotNull($result);
            self::assertSame(self::ID, $result->identification_id);
            self::assertSame($score, $result->risk_score);
            self::assertSame('2026-10-29T09:00:00.123Z', $result->toArray()['observed_at']);
            self::assertSame('203.0.113.24', $result->public_ip->ip);
            self::assertSame('google', $result->traffic_source->utm_source);
            self::assertTrue($result->detection_flags->vpn);
            self::assertTrue($result->detection_flags->rate_limited);
            self::assertSame([], $result->signals);
            self::assertSame($card, $result->raw);
        }
    }

    public function testListsPreserveUserFieldsUnknownRiskAndOpaqueCursor(): void
    {
        $card = self::card();
        $card['type'] = 'user';
        $card['id'] = 'a+b% c';
        $card['risk'] = ['state' => 'unavailable', 'band' => null];
        $card['related_counts'] = null;
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, ['data' => [$card], 'next_page_token' => 'opaque+/=']));
        $sdk = Clients::history($http);
        $page = $sdk->history->list('user', ['query' => ['ids' => ['a+b% c']]]);
        self::assertSame([$card], $page->data);
        self::assertSame('opaque+/=', $page->next_page_token);
        self::assertFalse(property_exists($page, 'total'));
        $sdk->history->list('user', ['query' => ['ids' => ['a+b% c'], 'page_token' => $page->next_page_token]]);
        parse_str($http->lastRequest()->getUri()->getQuery(), $query);
        self::assertSame('opaque+/=', $query['page_token']);
    }

    public function testCompatibilityGetRejectsUnavailableScore(): void
    {
        $card = self::card();
        $card['risk'] = ['state' => 'unavailable'];
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, ['data' => $card]));
        $this->expectException(ApiException::class);
        Clients::history($http)->identifications->get(self::ID, ['wait' => false]);
    }

    public function testMalformedEntityPagesAreRejected(): void
    {
        foreach ([['data' => 'bad'], ['data' => [[]]], ['data' => [], 'next_page_token' => 1]] as $body) {
            try {
                EntityPage::fromArray($body);
                self::fail('Malformed page accepted');
            } catch (ApiException $error) {
                self::assertStringContainsString('invalid', $error->getMessage());
            }
        }
    }
}
