<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Internal\Wire\Generated\HistoryRow;
use ShieldLabs\Internal\Wire\Read;
use ShieldLabs\Model\DomainProfile;
use ShieldLabs\Model\HistoryPage;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Tests\Support\MockHttpClient;
use ShieldLabs\Webhook;

final class GeneratedContractTest extends TestCase
{
    public function testReadersDoNotCoerceRawInput(): void
    {
        foreach ([null, false, 12, 12.9, '12', [], ['future' => true]] as $value) {
            self::assertSame($value, Read::integer(HistoryRow::score(), ['score' => $value]));
        }
        self::assertNull(Read::integer(HistoryRow::score(), []));
        self::assertSame('new-network', Read::text(HistoryRow::connection_type(), ['connection_type' => 'new-network']));
    }

    public function testHistoryStillNormalizesAndKeepsUnknownFields(): void
    {
        $body = Fixtures::json('history-page.json');
        $row = $body['data'][0];
        $row['future_optional'] = ['nested' => true];
        $row['connection_type'] = 'new-network';
        $row['score'] = 25.9;
        $row['is_vpn'] = 'yes';
        $row['created_at'] = null;
        $body = ['data' => [$row, null, false], 'total' => null];
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, $body));
        $page = Clients::history($http)->history->search('user_hid', 'anonymous');
        self::assertSame(1, $page->total);
        self::assertCount(1, $page->data);
        self::assertSame(25, $page->data[0]->risk_score);
        self::assertTrue($page->data[0]->detection_flags->vpn);
        self::assertSame('new-network', $page->data[0]->connection_type);
        self::assertNull($page->data[0]->observed_at);
        self::assertSame($row, $page->data[0]->raw);
        self::assertCount(0, HistoryPage::fromArray(['data' => 'wrong', 'total' => 'wrong']));
    }

    public function testProfileKeepsExistingMalformedDefaults(): void
    {
        $raw = ['Domain' => null, 'Weight' => -1.9, 'Secret' => false, 'PublicKey' => 12, 'CreatedAt' => [], 'future' => 'kept'];
        $profile = DomainProfile::fromArray($raw);
        self::assertSame('', $profile->domain);
        self::assertSame(-1, $profile->remaining_identifications);
        self::assertSame('', $profile->public_key_masked);
        self::assertSame('', $profile->secret_key_masked);
        self::assertNull($profile->created_at);
        self::assertSame($raw, $profile->raw);
    }

    public function testSignedWebhookKeepsUnknownAndMalformedValues(): void
    {
        $raw = Fixtures::json('webhook-identification-scored.json');
        $raw['schema_version'] = 'future';
        $raw['data']['risk_score'] = 'malformed';
        $raw['data']['user_hid'] = null;
        $raw['data']['connection_type'] = 'future-network';
        $raw['data']['detection_flags']['vpn'] = 'yes';
        $raw['data']['traffic_source']['channel'] = 'future-channel';
        $raw['data']['future_optional'] = ['a' => 1];
        $body = json_encode($raw, \JSON_THROW_ON_ERROR);
        $secret = 'whsec_fixture_generated_contract';
        $event = Webhook::constructEvent($body, 'sha256=' . hash_hmac('sha256', $body, $secret), $secret);
        self::assertSame(0, $event->data->risk_score);
        self::assertSame('future-network', $event->data->connection_type);
        self::assertSame('future-channel', $event->data->traffic_source->channel);
        self::assertTrue($event->data->detection_flags->vpn);
        self::assertNull($event->data->user_hid);
        self::assertSame($raw['data'], $event->data->raw);
    }
}
