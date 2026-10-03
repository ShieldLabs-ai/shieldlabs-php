<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class ProfileParameters
{
    /** @return Field<string> */
    public static function X_Shield_Domain(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('X-Shield-Domain');

        return $field;
    }

    /** Operation path. */
    public static function path(): string
    {
        return strtr('/v1/profile', []);
    }
}
