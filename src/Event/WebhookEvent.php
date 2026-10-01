<?php

declare(strict_types=1);

namespace ShieldLabs\Event;

/**
 * A verified webhook delivery. {@see \ShieldLabs\Webhook::constructEvent()} returns
 * one of {@see IdentificationScoredEvent}, {@see WebhookPingEvent} or
 * {@see UnknownWebhookEvent}.
 */
abstract class WebhookEvent
{
    /**
     * @param string                  $event_type     for example "identification.scored"
     * @param string                  $schema_version payload schema version, "2026-06-01" today (other values are accepted)
     * @param \DateTimeImmutable|null $created_at     when the event was created (UTC)
     * @param array<mixed>            $raw            the decoded body as received
     */
    public function __construct(
        public readonly string $event_type,
        public readonly string $schema_version,
        public readonly ?\DateTimeImmutable $created_at,
        public readonly array $raw = [],
    ) {}
}
