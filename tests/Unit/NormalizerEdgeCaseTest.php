<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Internal\Normalizer;
use ShieldLabs\Internal\Time;
use ShieldLabs\Model\Identification;

/**
 * Defensive parsing beyond the shared fixtures: never throw on odd or missing data.
 */
final class NormalizerEdgeCaseTest extends TestCase
{
    public function testAnEmptyHistoryRowProducesDefaults(): void
    {
        $identification = Identification::fromHistoryRow([]);

        self::assertSame('', $identification->request_id);
        self::assertNull($identification->user_hid);
        self::assertSame('', $identification->domain);
        self::assertSame(['ip' => '', 'country' => ''], $identification->public_ip->toArray());
        self::assertSame(0, $identification->risk_score);
        self::assertSame([], $identification->signals);
        self::assertSame([], $identification->detection_flags->active());
        self::assertNull($identification->observed_at);
        self::assertSame('history', $identification->source);
    }

    public function testAnEmptyWebhookPayloadProducesDefaults(): void
    {
        $identification = Identification::fromWebhookData([]);

        self::assertSame('', $identification->device_id);
        self::assertSame('', $identification->traffic_source->channel);
        self::assertSame([], $identification->detection_flags->active());
        self::assertSame('webhook', $identification->source);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableScoreDetails(): iterable
    {
        yield 'empty string' => [''];
        yield 'invalid JSON' => ['[{"Value":10,'];
        yield 'JSON object' => ['{"Value":10,"Description":"Is proxy"}'];
        yield 'JSON null' => ['null'];
        yield 'already decoded array' => [[['Value' => 10, 'Description' => 'Is proxy']]];
        yield 'number' => [5];
        yield 'null' => [null];
    }

    #[DataProvider('unusableScoreDetails')]
    public function testUnusableScoreDetailsGiveNoSignals(mixed $details): void
    {
        self::assertSame([], Identification::fromHistoryRow(['score_details' => $details])->signals);
    }

    public function testKeepsOnlyIntegerNonZeroWeights(): void
    {
        $details = json_encode([
            ['Value' => 10, 'Description' => 'Is proxy'],
            ['Value' => 0, 'Description' => 'Check Incomplete'],
            ['Value' => 10.5, 'Description' => 'Is abuser'],
            ['Value' => true, 'Description' => 'Is VPN'],
            ['Value' => '30', 'Description' => 'Stun is not checked'],
            ['Description' => 'No value'],
            ['Value' => -30, 'Description' => 'Stun passed (late arrival, corrected)'],
            ['Value' => 7, 'Description' => 42],
            'not an object',
            [1, 2],
            [],
        ]);

        $signals = array_map(
            static fn($signal): array => $signal->toArray(),
            Identification::fromHistoryRow(['score_details' => $details])->signals,
        );

        self::assertSame([
            ['name' => 'proxy', 'weight' => 10, 'description' => 'Is proxy'],
            ['name' => 'stun_late_correction', 'weight' => -30, 'description' => 'Stun passed (late arrival, corrected)'],
            ['name' => '42', 'weight' => 7, 'description' => '42'],
        ], $signals);
    }

    public function testIpMismatchFromTheLeakDetailOrDifferentAddresses(): void
    {
        $leakDetail = json_encode([['Value' => 0, 'Description' => 'IP ≠ leakIP (192.0.2.1 ≠ 198.51.100.2, source=shield)']]);

        self::assertTrue(Identification::fromHistoryRow(['score_details' => $leakDetail])->detection_flags->ip_mismatch);
        self::assertTrue(Identification::fromHistoryRow(['ip' => '192.0.2.1', 'web_rtc_ip' => '198.51.100.2'])->detection_flags->ip_mismatch);
        self::assertFalse(Identification::fromHistoryRow(['ip' => '192.0.2.1', 'web_rtc_ip' => '192.0.2.1'])->detection_flags->ip_mismatch);
        self::assertFalse(Identification::fromHistoryRow(['ip' => '192.0.2.1', 'web_rtc_ip' => '0.0.0.0'])->detection_flags->ip_mismatch);
        self::assertFalse(Identification::fromHistoryRow(['ip' => '192.0.2.1', 'web_rtc_ip' => '198.51.100.2', 'is_search_bot' => true])->detection_flags->ip_mismatch);
        self::assertFalse(Identification::fromHistoryRow(['score_details' => $leakDetail, 'is_search_bot' => true])->detection_flags->ip_mismatch);
    }

    public function testLocalIpPrefersTheLeakSource(): void
    {
        $row = [
            'web_rtc_ip' => '198.51.100.2', 'web_rtc_country' => 'Germany',
            'webrtc_leak_ip' => '203.0.113.9', 'webrtc_leak_country' => 'Spain',
        ];

        self::assertSame('198.51.100.2', Identification::fromHistoryRow($row + ['webrtc_leak_source' => 'none'])->local_ip->ip);
        self::assertSame('198.51.100.2', Identification::fromHistoryRow($row + ['webrtc_leak_source' => ' '])->local_ip->ip);
        self::assertSame(['ip' => '203.0.113.9', 'country' => 'Spain'], Identification::fromHistoryRow($row + ['webrtc_leak_source' => 'shield'])->local_ip->toArray());
    }

    public function testBrowserVpnProxyComesFromTheConnectionType(): void
    {
        self::assertTrue(Identification::fromHistoryRow(['connection_type' => 'browser_vpn_proxy'])->detection_flags->browser_vpn_proxy);
        self::assertFalse(Identification::fromHistoryRow(['connection_type' => 'vpn'])->detection_flags->browser_vpn_proxy);
    }

    public function testSiteDomainWinsOverTheRequestHost(): void
    {
        self::assertSame('example.com', Identification::fromHistoryRow(['domain' => 'shop.example.com', 'site_domain' => 'example.com'])->domain);
        self::assertSame('shop.example.com', Identification::fromHistoryRow(['domain' => 'shop.example.com', 'site_domain' => ''])->domain);
    }

    public function testFlagTruthinessFollowsJson(): void
    {
        $identification = Identification::fromHistoryRow(['is_vpn' => 'false', 'is_tor' => 0, 'is_proxy' => '', 'is_abuser' => 1, 'is_datacenter' => null]);

        self::assertSame(['vpn', 'abuser'], $identification->detection_flags->active());
    }

    public function testToleratesWrongTypes(): void
    {
        $identification = Identification::fromWebhookData([
            'request_id' => 12345,
            'user_hid' => false,
            'risk_score' => 45.0,
            'public_ip' => 'x',
            'traffic_source' => 'x',
            'detection_flags' => 'x',
            'signals' => ['name' => 'not a list'],
            'observed_at' => 1790769720,
        ]);

        self::assertSame('12345', $identification->request_id);
        self::assertNull($identification->user_hid);
        self::assertSame(45, $identification->risk_score);
        self::assertSame('', $identification->public_ip->ip);
        self::assertSame([], $identification->signals);
        self::assertNull($identification->observed_at);
    }

    public function testWebhookSignalsKeepZeroWeights(): void
    {
        $identification = Identification::fromWebhookData(['signals' => [['name' => 'x', 'weight' => 0], ['weight' => 5]]]);

        self::assertSame(
            [['name' => 'x', 'weight' => 0, 'description' => null], ['name' => '', 'weight' => 5, 'description' => null]],
            array_map(static fn($signal): array => $signal->toArray(), $identification->signals),
        );
    }

    public function testNormalizesIpSentinelsOnWebhooksToo(): void
    {
        $identification = Identification::fromWebhookData(['public_ip' => ['ip' => ' 0.0.0.0 ', 'country' => 'Germany'], 'local_ip' => ['ip' => ' 198.51.100.2 ']]);

        self::assertSame(['ip' => '', 'country' => 'Germany'], $identification->public_ip->toArray());
        self::assertSame('198.51.100.2', $identification->local_ip->ip);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function historyTimes(): iterable
    {
        yield 'milliseconds' => ['2026-09-30 12:34:56.123', '2026-09-30T12:34:56.123Z'];
        yield 'seconds' => ['2026-09-30 13:05:12', '2026-09-30T13:05:12.000Z'];
        yield 'one digit' => ['2026-09-30 13:10:00.5', '2026-09-30T13:10:00.500Z'];
        yield 'nine digits truncated' => ['2026-09-30 13:10:00.999999999', '2026-09-30T13:10:00.999Z'];
        yield 'T separator' => ['2026-09-30T13:10:00', '2026-09-30T13:10:00.000Z'];
        yield 'zone ignored' => ['2026-09-30 13:10:00+02:00', '2026-09-30T13:10:00.000Z'];
        yield 'surrounding space' => ["  2026-09-30 13:10:00.250\n", '2026-09-30T13:10:00.250Z'];
        yield 'empty' => ['', null];
        yield 'garbage' => ['yesterday', null];
        yield 'impossible date' => ['2026-02-30 10:00:00', null];
        yield 'not a string' => [1790769720, null];
        yield 'null' => [null, null];
    }

    #[DataProvider('historyTimes')]
    public function testParsesHistoryTimestamps(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, Time::format(Normalizer::parseHistoryTime($input)));
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function rfc3339Times(): iterable
    {
        yield 'nanoseconds' => ['2026-09-30T12:34:57.482913041Z', '2026-09-30T12:34:57.482Z'];
        yield 'seconds' => ['2026-09-30T12:34:56Z', '2026-09-30T12:34:56.000Z'];
        yield 'positive offset' => ['2026-09-30T14:34:56.100+02:00', '2026-09-30T12:34:56.100Z'];
        yield 'negative offset' => ['2026-09-30T07:04:56-05:30', '2026-09-30T12:34:56.000Z'];
        yield 'day boundary' => ['2026-10-01T01:00:00+03:00', '2026-09-30T22:00:00.000Z'];
        yield 'zero date' => ['0001-01-01T00:00:00Z', '0001-01-01T00:00:00.000Z'];
        yield 'space separator' => ['2026-09-30 12:34:56Z', null];
        yield 'lowercase z' => ['2026-09-30T12:34:56z', null];
        yield 'no zone' => ['2026-09-30T12:34:56', null];
        yield 'impossible date' => ['2026-13-01T00:00:00Z', null];
        yield 'not a string' => [false, null];
    }

    #[DataProvider('rfc3339Times')]
    public function testParsesRfc3339Timestamps(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, Time::format(Normalizer::parseRfc3339($input)));
    }

    public function testKeepsMicrosecondsInTheModel(): void
    {
        $value = Normalizer::parseRfc3339('2026-09-30T12:34:57.482913041Z');

        self::assertSame('482913', $value?->format('u'));
    }

    public function testStripsUnicodeWhitespace(): void
    {
        self::assertSame('a b', Normalizer::strip("\u{00A0}\u{2003}\t a b \u{3000}\n"));
        self::assertSame('x', Normalizer::strip("\x1Fx\x1C"));
        self::assertSame("\xFF", Normalizer::strip(" \xFF "), 'invalid UTF-8 falls back to ASCII trimming');
    }

    public function testFallbackSlugHandlesInvalidUtf8(): void
    {
        self::assertSame('abc', Normalizer::fallbackSlug("abc\xFF"));
    }

    public function testSlugsForUnicodeDigitsAndCase(): void
    {
        self::assertSame('ärger_größe_2', Normalizer::signalSlug('Ärger Größe 2'));
        self::assertSame('x_neq_y', Normalizer::signalSlug('x≠y'));
        self::assertSame('a_b', Normalizer::signalSlug('a - / b'));
        self::assertSame('unknown', Normalizer::signalSlug('Sticky verdict: (request 11111111-2222-4333-8444-555555555555)'));
    }
}
