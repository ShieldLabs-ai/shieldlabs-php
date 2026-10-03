<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class ScoredData
{
    /** @return Field<string> */
    public static function browser(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('browser');

        return $field;
    }

    /** @return Field<string> */
    public static function connection_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('connection_type');

        return $field;
    }

    /** @return Field<string> */
    public static function cookie_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('cookie_id');

        return $field;
    }

    /** @return Field<array<mixed>> */
    public static function detection_flags(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('detection_flags');

        return $field;
    }

    /** @return Field<string> */
    public static function device_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('device_id');

        return $field;
    }

    /** @return Field<string> */
    public static function device_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('device_type');

        return $field;
    }

    /** @return Field<string> */
    public static function domain(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('domain');

        return $field;
    }

    /** @return Field<array<mixed>> */
    public static function local_ip(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('local_ip');

        return $field;
    }

    /** @return Field<string> */
    public static function observed_at(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('observed_at');

        return $field;
    }

    /** @return Field<string> */
    public static function os(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('os');

        return $field;
    }

    /** @return Field<array<mixed>> */
    public static function public_ip(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('public_ip');

        return $field;
    }

    /** @return Field<string> */
    public static function request_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('request_id');

        return $field;
    }

    /** @return Field<int> */
    public static function risk_score(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('risk_score');

        return $field;
    }

    /** @return Field<string> */
    public static function session_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('session_id');

        return $field;
    }

    /** @return Field<array<mixed>> */
    public static function signals(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('signals');

        return $field;
    }

    /** @return Field<array<mixed>> */
    public static function traffic_source(): Field
    {
        /** @var Field<array<mixed>> $field */
        $field = new Field('traffic_source');

        return $field;
    }

    /** @return Field<string|null> */
    public static function user_hid(): Field
    {
        /** @var Field<string|null> $field */
        $field = new Field('user_hid');

        return $field;
    }

    /** @return Field<string> */
    public static function visitor_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('visitor_id');

        return $field;
    }
}
