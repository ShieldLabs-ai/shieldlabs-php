<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class DomainProfile
{
    /** @return Field<string> */
    public static function Callback(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('Callback');

        return $field;
    }

    /** @return Field<string> */
    public static function CreatedAt(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('CreatedAt');

        return $field;
    }

    /** @return Field<string> */
    public static function Domain(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('Domain');

        return $field;
    }

    /** @return Field<string> */
    public static function PublicKey(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('PublicKey');

        return $field;
    }

    /** @return Field<string> */
    public static function Secret(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('Secret');

        return $field;
    }

    /** @return Field<int> */
    public static function Weight(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('Weight');

        return $field;
    }
}
