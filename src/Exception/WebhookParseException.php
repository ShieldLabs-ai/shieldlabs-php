<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * A correctly signed webhook body could not be parsed into an event.
 */
final class WebhookParseException extends ShieldLabsException {}
