<?php

declare(strict_types=1);

namespace ShieldLabs\Event;

use ShieldLabs\Model\Identification;

/**
 * `identification.scored`: the verdict for one identification. Today it is sent once
 * per identification and endpoint (one attempt, 1-second timeout, no retries).
 * Future retries resend identical bytes, so make your handler idempotent on
 * `$event->data->request_id`, and use the History API for guaranteed reads.
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
