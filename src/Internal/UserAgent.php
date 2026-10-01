<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use ShieldLabs\ShieldLabs;

/**
 * @internal
 */
final class UserAgent
{
    private function __construct() {}

    public static function value(): string
    {
        return \sprintf('shieldlabs-php/%s (PHP %s; %s)', ShieldLabs::VERSION, \PHP_VERSION, \PHP_OS_FAMILY);
    }
}
