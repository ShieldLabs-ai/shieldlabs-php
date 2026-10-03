<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class HistoryPage
{
    /** @return Field<array<mixed>> */
    public static function data(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('data');

        return $field;
    }

    /** @return Field<int> */
    public static function total(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('total');

        return $field;
    }
}
