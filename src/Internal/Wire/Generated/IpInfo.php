<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class IpInfo
{
    /** @return Field<string> */
    public static function country(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('country');

        return $field;
    }

    /** @return Field<string> */
    public static function ip(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('ip');

        return $field;
    }
}
