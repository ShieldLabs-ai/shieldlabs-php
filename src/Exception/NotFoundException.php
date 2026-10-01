<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * HTTP 404: the path does not exist (often a wrong base URL).
 */
final class NotFoundException extends ApiException {}
