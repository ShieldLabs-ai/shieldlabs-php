<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Event\IdentificationScoredEvent;
use ShieldLabs\Event\UnknownWebhookEvent;
use ShieldLabs\Event\WebhookEvent;
use ShieldLabs\Event\WebhookPingEvent;
use ShieldLabs\Exception\SignatureVerificationException;
use ShieldLabs\Exception\WebhookParseException;
use ShieldLabs\Model\DetectionFlags;
use ShieldLabs\RiskBand;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Webhook;

final class WebhookEventTest extends TestCase
{
    private const SECRET = 'whsec_00112233445566778899aabbccddeeff';

    private static function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * @return array<mixed>
     */
    private static function expected(string $caseName): array
    {
        foreach (Fixtures::json('normalization-cases.json')['cases'] as $case) {
            \assert(\is_array($case));
            if ($case['name'] === $caseName) {
                \assert(\is_array($case['expected']));

                return $case['expected'];
            }
        }

        throw new \LogicException('No case ' . $caseName);
    }

    public function testParsesTheScoredEventFromTheExactBytes(): void
    {
        $body = Fixtures::raw('webhook-identification-scored.raw.txt');
        $event = Webhook::constructEvent($body, self::sign($body), self::SECRET);

        self::assertInstanceOf(IdentificationScoredEvent::class, $event);
        self::assertSame('identification.scored', $event->event_type);
        self::assertSame('2026-06-01', $event->schema_version);
        self::assertSame('2026-09-30T12:34:57.482913+00:00', $event->created_at?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(self::expected('webhook_scored'), $event->data->toArray());
        self::assertSame('https://shop.example.com/signup?utm_source=google&utm_medium=cpc&gclid=abc123', $event->data->traffic_source->landing_url);
        self::assertSame(RiskBand::Dangerous, $event->data->riskBand());
        self::assertSame(['proxy', 'datacenter_ip', 'anti_detect_browser', 'ip_mismatch', 'suspicious_paid_click'], $event->data->detection_flags->active());
        self::assertIsArray($event->raw['data']);
        self::assertSame($event->raw['data'], $event->data->raw);
    }

    public function testPrettyAndCompactScoredBodiesGiveTheSameIdentification(): void
    {
        $pretty = Fixtures::raw('webhook-identification-scored.json');
        $compact = Fixtures::raw('webhook-identification-scored.raw.txt');
        $a = Webhook::constructEvent($pretty, self::sign($pretty), self::SECRET);
        $b = Webhook::constructEvent($compact, self::sign($compact), self::SECRET);

        self::assertInstanceOf(IdentificationScoredEvent::class, $a);
        self::assertInstanceOf(IdentificationScoredEvent::class, $b);
        self::assertSame($a->data->toArray(), $b->data->toArray());
    }

    public function testParsesTheVerifyPing(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');
        $event = Webhook::constructEvent($body, 'sha256=ea2685733d254f7028fb031c4214583b0650de01e6c8c93131236024edd9fdd8', self::SECRET);

        self::assertInstanceOf(WebhookPingEvent::class, $event);
        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('webhook.ping', $event->event_type);
        self::assertSame('2026-06-01', $event->schema_version);
        self::assertSame('2026-09-30T12:34:56+00:00', $event->created_at?->format(\DATE_ATOM));
        self::assertArrayNotHasKey('data', $event->raw);
    }

    public function testPrettyPingParsesLikeTheExactBytes(): void
    {
        $pretty = Fixtures::raw('webhook-ping.json');
        $event = Webhook::constructEvent($pretty, self::sign($pretty), self::SECRET);

        self::assertInstanceOf(WebhookPingEvent::class, $event);
        self::assertSame(Fixtures::json('webhook-ping.json'), $event->raw);
        self::assertSame(json_decode(Fixtures::raw('webhook-ping.raw.txt'), true), $event->raw);
        self::assertFalse(Webhook::verifySignature($pretty, 'sha256=ea2685733d254f7028fb031c4214583b0650de01e6c8c93131236024edd9fdd8', self::SECRET));
    }

    public function testParsesTheRateLimitMarkerEvent(): void
    {
        $body = Fixtures::raw('webhook-rate-limited.json');
        $event = Webhook::constructEvent($body, self::sign($body), self::SECRET);

        self::assertInstanceOf(IdentificationScoredEvent::class, $event);
        self::assertSame(self::expected('webhook_rate_limited'), $event->data->toArray());
        self::assertTrue($event->data->isRateLimited());
        self::assertSame(RiskBand::RateLimited, $event->data->riskBand());
        self::assertFalse($event->data->hasDeviceSignals());
        self::assertSame('2026-09-30T13:10:00.500000', $event->created_at?->format('Y-m-d\TH:i:s.u'));
    }

    public function testParsesTheDashboardTestDeliveryWithSeventeenFlags(): void
    {
        $body = Fixtures::raw('webhook-test-delivery.json');
        $decoded = Fixtures::json('webhook-test-delivery.json');
        \assert(\is_array($decoded['data']) && \is_array($decoded['data']['detection_flags']));
        self::assertCount(17, $decoded['data']['detection_flags']);

        $event = Webhook::constructEvent($body, self::sign($body), self::SECRET);

        self::assertInstanceOf(IdentificationScoredEvent::class, $event);
        self::assertSame(self::expected('webhook_test_delivery'), $event->data->toArray());
        self::assertFalse($event->data->detection_flags->browser_automation);
        self::assertFalse($event->data->detection_flags->search_bot);
        self::assertCount(\count(DetectionFlags::KEYS), $event->data->detection_flags->toArray());
        self::assertNull($event->data->user_hid);
        self::assertSame('2026-09-30T12:34:56.000Z', $event->data->toArray()['observed_at']);
    }

    public function testReturnsAnUnknownEventForNewEventTypes(): void
    {
        $body = '{"event_type":"identification.refined","schema_version":"2027-01-01","created_at":"2027-01-01T00:00:00Z","data":{"x":1}}';
        $event = Webhook::constructEvent($body, self::sign($body), self::SECRET);

        self::assertInstanceOf(UnknownWebhookEvent::class, $event);
        self::assertSame('identification.refined', $event->event_type);
        self::assertSame('2027-01-01', $event->schema_version);
        self::assertSame(['x' => 1], $event->raw['data']);
    }

    public function testAcceptsAnUnknownSchemaVersionAndTolerantData(): void
    {
        $body = '{"event_type":"identification.scored","schema_version":"2099-01-01","created_at":"not a date","data":{"request_id":"3b241101-e2bb-4255-8caf-4136c566a962","extra":{"nested":true}}}';
        $event = Webhook::constructEvent($body, self::sign($body), self::SECRET);

        self::assertInstanceOf(IdentificationScoredEvent::class, $event);
        self::assertSame('2099-01-01', $event->schema_version);
        self::assertNull($event->created_at);
        self::assertSame('3b241101-e2bb-4255-8caf-4136c566a962', $event->data->request_id);
        self::assertSame(0, $event->data->risk_score);
        self::assertSame([], $event->data->signals);
        self::assertNull($event->data->observed_at);
    }

    public function testRejectsABadSignatureBeforeParsing(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('does not match');
        Webhook::constructEvent('not json', self::sign('other body'), self::SECRET);
    }

    public function testExplainsAMissingHeader(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('missing');
        Webhook::constructEvent('{}', '  ', self::SECRET);
    }

    public function testExplainsAMalformedHeader(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('malformed');
        Webhook::constructEvent('{}', 'sha1=abc', self::SECRET);
    }

    public function testExplainsAMissingSecret(): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('No webhook signing secret');
        Webhook::constructEvent('{}', self::sign('{}'), '');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'invalid JSON' => ['{"event_type":', 'not valid JSON'];
        yield 'JSON list' => ['[1,2]', 'not a JSON object'];
        yield 'JSON string' => ['"x"', 'not a JSON object'];
        yield 'no event type' => ['{"schema_version":"2026-06-01"}', 'no event_type'];
        yield 'empty object' => ['{}', 'no event_type'];
        yield 'scored without data' => ['{"event_type":"identification.scored"}', 'no data object'];
        yield 'scored with list data' => ['{"event_type":"identification.scored","data":[1]}', 'no data object'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBodies')]
    public function testRejectsAVerifiedBodyThatIsNotAnEvent(string $body, string $message): void
    {
        $this->expectException(WebhookParseException::class);
        $this->expectExceptionMessage($message);
        Webhook::constructEvent($body, self::sign($body), self::SECRET);
    }

    public function testAcceptsASecretListDuringRotation(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');
        $event = Webhook::constructEvent($body, self::sign($body), ['whsec_new_secret_not_yet_live', self::SECRET]);

        self::assertInstanceOf(WebhookPingEvent::class, $event);
    }
}
