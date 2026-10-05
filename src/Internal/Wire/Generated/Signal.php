<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class Signal
{
    /** @return Field<string> */
    public static function name(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('name');

        return $field;
    }

    /** @return Field<int> */
    public static function weight(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('weight');

        return $field;
    }
}
