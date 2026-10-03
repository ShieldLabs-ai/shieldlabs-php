<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class WebhookEnvelope
{
    /** @return Field<string> */
    public static function created_at(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('created_at');

        return $field;
    }

    /** @return Field<array<mixed>> */
    public static function data(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('data');

        return $field;
    }

    /** @return Field<string> */
    public static function event_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('event_type');

        return $field;
    }

    /** @return Field<string> */
    public static function schema_version(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('schema_version');

        return $field;
    }
}
