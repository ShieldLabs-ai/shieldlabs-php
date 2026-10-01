<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * HTTP 5xx: the API or an edge proxy failed. Retried automatically.
 */
final class ServerException extends ApiException {}
