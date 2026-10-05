<?php

declare(strict_types=1);

namespace ShieldLabs\Event;

use ShieldLabs\Model\Identification;

/**
 * One final identification. Retries within the bounded delivery window reuse
 * event_id and exact body bytes. Deduplicate by event_id (legacy: request_id),
 * persist before 2xx, and use History for recovery/latest state.
 */
final class IdentificationScoredEvent extends WebhookEvent
{
    public const TYPE = 'identification.scored';

    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        string $event_type,
        string $schema_version,
        ?\DateTimeImmutable $created_at,
        public readonly Identification $data,
        array $raw = [],
    ) {
        parent::__construct($event_type, $schema_version, $created_at, $raw);
    }
}
