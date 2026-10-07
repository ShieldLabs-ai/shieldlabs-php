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
    public readonly ?string $event_id;
    public readonly ?int $site_id;

    /**
     * @param string                  $event_type     for example "identification.scored"
     * @param string                  $schema_version payload schema version, "2026-10-06" (legacy 2026-06-01 accepted) (other values are accepted)
     * @param \DateTimeImmutable|null $created_at     when the event was created (UTC)
     * @param array<mixed>            $raw            the decoded body as received
     */
    public function __construct(
        public readonly string $event_type,
        public readonly string $schema_version,
        public readonly ?\DateTimeImmutable $created_at,
        public readonly array $raw = [],
    ) {
        $this->event_id = \is_string($raw['event_id'] ?? null) ? $raw['event_id'] : null;
        $this->site_id = \is_int($raw['site_id'] ?? null) ? $raw['site_id'] : null;
    }
}
