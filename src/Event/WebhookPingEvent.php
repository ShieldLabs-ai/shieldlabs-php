<?php

declare(strict_types=1);

namespace ShieldLabs\Event;

/**
 * `webhook.ping`: sent when you verify an endpoint in the analytics dashboard.
 * Answer with any 2xx status.
 */
final class WebhookPingEvent extends WebhookEvent
{
    public const TYPE = 'webhook.ping';
}
