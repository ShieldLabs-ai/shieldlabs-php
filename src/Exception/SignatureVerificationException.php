<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * A webhook delivery failed signature verification (missing or malformed header,
 * no signing secret configured, or a signature that does not match the raw body).
 */
final class SignatureVerificationException extends ShieldLabsException {}
