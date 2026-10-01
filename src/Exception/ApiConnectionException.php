<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * The HTTP request could not be completed (DNS, TCP, TLS or another network failure).
 */
final class ApiConnectionException extends ShieldLabsException {}
