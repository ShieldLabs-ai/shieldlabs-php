<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class ScoreDetail
{
    /** @return Field<string> */
    public static function Description(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('Description');

        return $field;
    }

    /** @return Field<int> */
    public static function Value(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('Value');

        return $field;
    }
}
