<?php

declare(strict_types=1);

namespace ShieldLabs\Event;

/**
 * A verified event whose `event_type` this SDK version does not know. The decoded
 * body is available in `$raw`. Acknowledge it with a 2xx status.
 */
final class UnknownWebhookEvent extends WebhookEvent {}
