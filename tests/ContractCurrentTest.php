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
        self::assertCount(19, $event->data->risk_events ?? []);
        self::assertSame('sample-hardware', $event->data->fingerprint['hardware_id'] ?? null);
        self::assertNotSame($event->data->device_id, $event->data->fingerprint['hardware_id'] ?? null);
        self::assertSame('no_history', $event->data->hre['account_takeover']['reason'] ?? null);
        self::assertArrayHasKey('risk_events', $event->data->toArray());
        self::assertFalse(Webhook::verifySignature($raw . ' ', $signature, 'secret'));
    }
}
