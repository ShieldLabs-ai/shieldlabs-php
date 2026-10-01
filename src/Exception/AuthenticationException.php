<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * HTTP 401 or 403: the key is missing, unknown, disabled or does not match the domain.
 */
final class AuthenticationException extends ApiException {}
