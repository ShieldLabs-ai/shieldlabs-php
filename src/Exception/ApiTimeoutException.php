<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * An HTTP attempt did not finish within the configured timeout.
 */
final class ApiTimeoutException extends ShieldLabsException {}
