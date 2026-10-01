<?php

declare(strict_types=1);

namespace ShieldLabs\Exception;

/**
 * Base class of every exception thrown by the ShieldLabs SDK.
 *
 * Catch this type to handle any SDK failure in one place.
 */
class ShieldLabsException extends \RuntimeException {}
