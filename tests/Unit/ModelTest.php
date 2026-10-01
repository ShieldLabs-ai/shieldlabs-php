<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\ConnectionType;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\LookupType;
use ShieldLabs\Model\DetectionFlags;
use ShieldLabs\Model\HistoryPage;
use ShieldLabs\Model\Identification;
use ShieldLabs\Model\Signal;
use ShieldLabs\RiskBand;
use ShieldLabs\SignalName;
use ShieldLabs\Tests\Support\Fixtures;

final class ModelTest extends TestCase
{
    private static function first(): Identification
    {
        $page = HistoryPage::fromArray(Fixtures::json('history-page.json'));

        return $page->data[0];
    }

    public function testExposesSnakeCaseProperties(): void
    {
        $identification = self::first();

        self::assertSame('02f1d973-84db-4156-a7f7-e799e6bf389b', $identification->request_id);
        self::assertSame('9f86d081884c7d659a2feaa0c55ad015', $identification->user_hid);
        self::assertSame('example.com', $identification->domain);
        self::assertSame('Netherlands', $identification->public_ip->country);
        self::assertSame('198.51.100.23', $identification->local_ip->ip);
        self::assertSame(ConnectionType::PROXY, $identification->connection_type);
        self::assertSame('Google Ads', $identification->traffic_source->channel);
        self::assertSame(80, $identification->risk_score);
        self::assertSame(RiskBand::Dangerous, $identification->riskBand());
        self::assertFalse($identification->isRateLimited());
        self::assertTrue($identification->hasDeviceSignals());
        self::assertTrue($identification->detection_flags->anti_detect_browser);
        self::assertSame(SignalName::ANTIDETECT_BROWSER, $identification->signals[2]->name);
        self::assertSame('Antidetect browser (turn_block)', $identification->signals[2]->description);
        self::assertSame(1790771696123, $identification->raw['ver']);
    }

    public function testObservedAtIsUtcWithMilliseconds(): void
    {
        $observedAt = self::first()->observed_at;

        self::assertNotNull($observedAt);
        self::assertSame('2026-09-30 12:34:56.123000 UTC', $observedAt->format('Y-m-d H:i:s.u T'));
    }

    public function testSerializesToTheNormalizedShape(): void
    {
        $identification = self::first();
        $json = json_decode((string) json_encode($identification), true);

        self::assertSame($identification->toArray(), $json);
        self::assertArrayNotHasKey('raw', $json);
        self::assertSame('2026-09-30T12:34:56.123Z', $json['observed_at']);
    }

    public function testSignalsAreAnOpenSet(): void
    {
        $identification = Identification::fromWebhookData([
            'signals' => [['name' => 'brand_new_signal', 'weight' => 25], ['name' => 'stun_late_correction', 'weight' => -30], 'junk'],
        ]);

        self::assertSame(
            [['name' => 'brand_new_signal', 'weight' => 25, 'description' => null], ['name' => 'stun_late_correction', 'weight' => -30, 'description' => null]],
            array_map(static fn(Signal $signal): array => $signal->jsonSerialize(), $identification->signals),
        );
    }

    public function testDetectionFlagsLookup(): void
    {
        $flags = new DetectionFlags(vpn: true, check_incomplete: true);

        self::assertTrue($flags->get('vpn'));
        self::assertFalse($flags->get('tor'));
        self::assertSame(['vpn', 'check_incomplete'], $flags->active());
        self::assertSame(DetectionFlags::KEYS, array_keys($flags->toArray()));
        self::assertSame($flags->toArray(), $flags->jsonSerialize());
        self::assertCount(19, DetectionFlags::KEYS);

        $this->expectException(ValidationException::class);
        $flags->get('bot');
    }

    public function testDetectionFlagsFromArrayIgnoresNonBooleans(): void
    {
        /** @var array<string, bool> $input */
        $input = ['vpn' => true, 'tor' => 1, 'proxy' => 'true', 'unknown_flag' => true];
        $flags = DetectionFlags::fromArray($input);

        self::assertSame(['vpn'], $flags->active());
    }

    public function testNilDeviceIdMeansNoDeviceSignals(): void
    {
        $identification = Identification::fromWebhookData(Fixtures::json('webhook-rate-limited.json')['data'] ?? []);

        self::assertSame(Identification::NIL_UUID, $identification->device_id);
        self::assertFalse($identification->hasDeviceSignals());
        self::assertTrue($identification->isRateLimited());
    }

    public function testHistoryPageIsCountableAndIterable(): void
    {
        $page = HistoryPage::fromArray(Fixtures::json('history-page.json'));

        self::assertCount(5, $page);
        self::assertSame(5, iterator_count($page->getIterator()));
        self::assertSame(37, $page->total);
    }

    public function testLookupTypeKnowsItsUuidTypes(): void
    {
        $uuidTypes = array_values(array_filter(LookupType::cases(), static fn(LookupType $type): bool => $type->isUuid()));

        self::assertSame(
            [LookupType::VisitorId, LookupType::RequestId, LookupType::DeviceId, LookupType::SessionId, LookupType::CookieId],
            $uuidTypes,
        );
        self::assertSame(['ip', 'user_hid', 'visitor_id', 'request_id', 'device_id', 'session_id', 'cookie_id'], array_map(static fn(LookupType $type): string => $type->value, LookupType::cases()));
    }

    public function testIpInfoAndTrafficSourceSerialize(): void
    {
        $identification = self::first();

        self::assertSame(['ip' => '203.0.113.24', 'country' => 'Netherlands'], $identification->public_ip->jsonSerialize());
        self::assertSame($identification->traffic_source->toArray(), $identification->traffic_source->jsonSerialize());
        self::assertCount(9, $identification->traffic_source->toArray());
    }
}
