<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class TrafficSource
{
    /** @return Field<string> */
    public static function channel(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('channel');

        return $field;
    }

    /** @return Field<string> */
    public static function click_id_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('click_id_type');

        return $field;
    }

    /** @return Field<string> */
    public static function landing_url(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('landing_url');

        return $field;
    }

    /** @return Field<string> */
    public static function referrer_domain(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('referrer_domain');

        return $field;
    }

    /** @return Field<string> */
    public static function utm_campaign(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('utm_campaign');

        return $field;
    }

    /** @return Field<string> */
    public static function utm_content(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('utm_content');

        return $field;
    }

    /** @return Field<string> */
    public static function utm_medium(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('utm_medium');

        return $field;
    }

    /** @return Field<string> */
    public static function utm_source(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('utm_source');

        return $field;
    }

    /** @return Field<string> */
    public static function utm_term(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('utm_term');

        return $field;
    }
}
