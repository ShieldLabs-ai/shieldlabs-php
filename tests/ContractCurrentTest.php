<?php

declare(strict_types=1);

namespace ShieldLabs\Tests;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Event\IdentificationScoredEvent;
use ShieldLabs\Webhook;

final class ContractCurrentTest extends TestCase
{
    public function testCurrentCoreEnvelopePreservesNewFields(): void
    {
        $raw = file_get_contents(__DIR__ . '/data/contract-current.json');
        self::assertIsString($raw);
        $signature = 'sha256=' . hash_hmac('sha256', $raw, 'secret');
        $event = Webhook::constructEvent($raw, $signature, 'secret');
        self::assertInstanceOf(IdentificationScoredEvent::class, $event);
        self::assertNotEmpty($event->event_id);
        self::assertSame(7, $event->site_id);
        self::assertNull($event->data->risk_events);
        self::assertNull($event->data->fingerprint);
        self::assertNull($event->data->hre['account_sharing']['cluster_id']);
        self::assertSame('no_history', $event->data->hre['account_takeover']['reason'] ?? null);
        self::assertArrayNotHasKey('risk_events', $event->data->toArray());
        self::assertFalse(Webhook::verifySignature($raw . ' ', $signature, 'secret'));
    }
    public function testAcceptedBotOwnerSurvivesNormalization(): void
    {
        $raw = file_get_contents(__DIR__ . '/data/ai-bot.json');
        self::assertIsString($raw);
        $event = Webhook::constructEvent($raw, 'sha256=' . hash_hmac('sha256', $raw, 'secret'), 'secret');
        self::assertInstanceOf(IdentificationScoredEvent::class, $event);
        self::assertSame('OpenAI', $event->data->ai_bot_owner);
        self::assertTrue($event->data->detection_flags->ai_bot);
        self::assertSame('OpenAI', $event->data->toArray()['ai_bot_owner']);
    }

}
