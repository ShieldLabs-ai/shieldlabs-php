<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class HistoryParameters
{
    /** @return Field<int> */
    public static function limit(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('limit');

        return $field;
    }

    /** @return Field<int> */
    public static function offset(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('offset');

        return $field;
    }

    /** @return Field<string> */
    public static function search_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('search_type');

        return $field;
    }

    /** @return Field<string> */
    public static function value(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('value');

        return $field;
    }

    /**
     * Path values must already be validated and escaped.
     * @param 'request_id'|'device_id'|'user_hid'|'visitor_id'|'ip'|'session_id'|'cookie_id' $search_type
     * @param string $value
     */
    public static function path(string $search_type, string $value): string
    {
        return strtr('/api/v1/history/{search_type}/{value}', ['{search_type}' => (string) $search_type, '{value}' => (string) $value]);
    }
}
