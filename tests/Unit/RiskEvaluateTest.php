<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\EvaluationReason;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Model\DetectionFlags;
use ShieldLabs\Model\Identification;
use ShieldLabs\Model\IpInfo;
use ShieldLabs\Model\TrafficSource;
use ShieldLabs\Risk;
use ShieldLabs\RiskBand;

final class RiskEvaluateTest extends TestCase
{
    private const OBSERVED = '2026-09-30T12:00:00+00:00';
    private const NOW = 1790769720; // 2026-09-30T12:02:00Z, two minutes after OBSERVED

    private static function identification(
        int $score = 10,
        string $deviceId = 'd8e0f2a4-b6c8-4d0e-bf2a-4b6c8d0e2f4a',
        ?DetectionFlags $flags = null,
        ?string $observedAt = self::OBSERVED,
    ): Identification {
        return new Identification(
            request_id: '3b241101-e2bb-4255-8caf-4136c566a962',
            visitor_id: 'e9f1a3b5-c7d9-4e1f-8a3b-5c7d9e1f3a5b',
            device_id: $deviceId,
            session_id: 'b6c8d0e2-f4a6-4b8c-8d0e-2f4a6b8c0d2e',
            cookie_id: 'c7d9e1f3-a5b7-4c9d-ae1f-3a5b7c9d1e3f',
            user_hid: '9f86d081884c7d659a2feaa0c55ad015',
            domain: 'example.com',
            public_ip: new IpInfo('203.0.113.24', 'Netherlands'),
            local_ip: new IpInfo(),
            connection_type: 'direct',
            os: 'Windows',
            browser: 'Chrome',
            device_type: 'desktop',
            traffic_source: new TrafficSource(channel: 'Direct'),
            risk_score: $score,
            signals: [],
            detection_flags: $flags ?? new DetectionFlags(),
            observed_at: $observedAt === null ? null : new \DateTimeImmutable($observedAt),
            source: Identification::SOURCE_HISTORY,
        );
    }

    public function testNowConstantMatchesTheObservedTime(): void
    {
        self::assertSame(self::NOW - 120, (new \DateTimeImmutable(self::OBSERVED))->getTimestamp());
    }

    public function testAllowsAFreshTrustedIdentification(): void
    {
        $evaluation = Risk::evaluate(self::identification(), ['now' => self::NOW]);

        self::assertTrue($evaluation->ok);
        self::assertNull($evaluation->reason);
        self::assertSame(RiskBand::Trusted, $evaluation->band);
        self::assertNull($evaluation->flag);
        self::assertSame(['ok' => true, 'reason' => null, 'band' => 'trusted', 'flag' => null], $evaluation->toArray());
        self::assertSame('{"ok":true,"reason":null,"band":"trusted","flag":null}', json_encode($evaluation));
    }

    public function testSuspiciousPassesWithTheDefaults(): void
    {
        $evaluation = Risk::evaluate(self::identification(45), ['now' => self::NOW]);

        self::assertTrue($evaluation->ok);
        self::assertSame(RiskBand::Suspicious, $evaluation->band);
    }

    public function testMissingIdentificationIsNeverClean(): void
    {
        $evaluation = Risk::evaluate(null);

        self::assertFalse($evaluation->ok);
        self::assertSame(EvaluationReason::Missing, $evaluation->reason);
        self::assertNull($evaluation->band);
    }

    public function testReplayCallbackRunsBeforeTheOtherChecks(): void
    {
        $seen = [];
        $isReplay = static function (string $requestId) use (&$seen): bool {
            $seen[] = $requestId;

            return true;
        };

        $evaluation = Risk::evaluate(self::identification(999, observedAt: null), ['is_replay' => $isReplay]);

        self::assertSame(EvaluationReason::Replayed, $evaluation->reason);
        self::assertSame(RiskBand::RateLimited, $evaluation->band);
        self::assertSame(['3b241101-e2bb-4255-8caf-4136c566a962'], $seen);
    }

    public function testReplayCallbackReturningFalseContinues(): void
    {
        $evaluation = Risk::evaluate(self::identification(), ['now' => self::NOW, 'is_replay' => static fn(string $id): bool => false]);

        self::assertTrue($evaluation->ok);
    }

    public function testStaleIdentificationsAreRefused(): void
    {
        self::assertSame(EvaluationReason::Stale, Risk::evaluate(self::identification(), ['now' => self::NOW + 181])->reason);
        self::assertTrue(Risk::evaluate(self::identification(), ['now' => self::NOW + 180])->ok, 'exactly 300 s old is still fresh');
        self::assertSame(EvaluationReason::Stale, Risk::evaluate(self::identification(), ['now' => self::NOW, 'max_age' => 60])->reason);
        self::assertTrue(Risk::evaluate(self::identification(), ['now' => self::NOW, 'max_age' => 120.5])->ok);
    }

    public function testMissingTimestampIsStale(): void
    {
        self::assertSame(EvaluationReason::Stale, Risk::evaluate(self::identification(observedAt: null), ['now' => self::NOW])->reason);
    }

    public function testAcceptsNowAsADateTime(): void
    {
        $now = new \DateTimeImmutable('2026-09-30T14:05:00+02:00');

        self::assertTrue(Risk::evaluate(self::identification(), ['now' => $now])->ok);
        self::assertSame(EvaluationReason::Stale, Risk::evaluate(self::identification(), ['now' => $now->modify('+10 minutes')])->reason);
    }

    public function testDefaultNowIsTheCurrentTime(): void
    {
        $fresh = self::identification(observedAt: (new \DateTimeImmutable('-30 seconds'))->format(\DATE_ATOM));

        self::assertTrue(Risk::evaluate($fresh)->ok);
        self::assertSame(EvaluationReason::Stale, Risk::evaluate(self::identification(observedAt: '2020-01-01T00:00:00Z'))->reason);
    }

    public function testRateLimitMarkerComesBeforeDeviceAndFlags(): void
    {
        $evaluation = Risk::evaluate(
            self::identification(999, Identification::NIL_UUID, new DetectionFlags(browser_automation: true)),
            ['now' => self::NOW],
        );

        self::assertSame(EvaluationReason::RateLimited, $evaluation->reason);
        self::assertSame(RiskBand::RateLimited, $evaluation->band);
    }

    public function testNilDeviceIdMeansNoDeviceSignals(): void
    {
        $evaluation = Risk::evaluate(self::identification(5, Identification::NIL_UUID, new DetectionFlags(javascript_disabled: true)), ['now' => self::NOW]);

        self::assertSame(EvaluationReason::NoDeviceSignals, $evaluation->reason);
        self::assertSame(RiskBand::Trusted, $evaluation->band);
        self::assertSame(EvaluationReason::NoDeviceSignals, Risk::evaluate(self::identification(deviceId: ''), ['now' => self::NOW])->reason);
    }

    public function testBlocksAutomationAndDisabledJavaScriptByDefault(): void
    {
        $automation = Risk::evaluate(self::identification(60, flags: new DetectionFlags(browser_automation: true)), ['now' => self::NOW]);
        self::assertSame(EvaluationReason::BlockedFlag, $automation->reason);
        self::assertSame('browser_automation', $automation->flag);
        self::assertSame(RiskBand::Dangerous, $automation->band);

        $noJs = Risk::evaluate(self::identification(flags: new DetectionFlags(javascript_disabled: true)), ['now' => self::NOW]);
        self::assertSame('javascript_disabled', $noJs->flag);

        self::assertTrue(Risk::evaluate(self::identification(flags: new DetectionFlags(vpn: true, incognito: true)), ['now' => self::NOW])->ok);
    }

    public function testBlocksTheDangerousBandByDefault(): void
    {
        $evaluation = Risk::evaluate(self::identification(60), ['now' => self::NOW]);

        self::assertFalse($evaluation->ok);
        self::assertSame(EvaluationReason::BlockedBand, $evaluation->reason);
        self::assertSame(RiskBand::Dangerous, $evaluation->band);
    }

    public function testCustomBandsAndFlags(): void
    {
        $options = ['now' => self::NOW, 'block_bands' => ['suspicious', RiskBand::Dangerous], 'block_flags' => ['vpn', 'tor']];

        self::assertSame(EvaluationReason::BlockedBand, Risk::evaluate(self::identification(30), $options)->reason);
        $vpn = Risk::evaluate(self::identification(10, flags: new DetectionFlags(vpn: true)), $options);
        self::assertSame(['blocked_flag', 'vpn'], [$vpn->reason?->value, $vpn->flag]);
        self::assertTrue(Risk::evaluate(self::identification(10, flags: new DetectionFlags(browser_automation: true)), $options)->ok);
        self::assertTrue(Risk::evaluate(self::identification(95), ['now' => self::NOW, 'block_bands' => [], 'block_flags' => []])->ok);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['maxAge' => 10]];
        yield 'negative max age' => [['max_age' => -1]];
        yield 'max age as string' => [['max_age' => '300']];
        yield 'bad now' => [['now' => 'yesterday']];
        yield 'bad band' => [['block_bands' => ['high']]];
        yield 'bands not a list' => [['block_bands' => 'dangerous']];
        yield 'bad flag' => [['block_flags' => ['bot']]];
        yield 'flags not a list' => [['block_flags' => 'vpn']];
        yield 'replay not callable' => [['is_replay' => 'not a function name']];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(array $options): void
    {
        $this->expectException(ValidationException::class);
        /** @phpstan-ignore argument.type */
        Risk::evaluate(self::identification(), $options);
    }
}
