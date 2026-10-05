<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class DetectionFlags
{
    /** @return Field<bool> */
    public static function abuser(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('abuser');

        return $field;
    }

    /** @return Field<bool> */
    public static function anti_detect_browser(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('anti_detect_browser');

        return $field;
    }

    /** @return Field<bool> */
    public static function browser_automation(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('browser_automation');

        return $field;
    }

    /** @return Field<bool> */
    public static function browser_vpn_proxy(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('browser_vpn_proxy');

        return $field;
    }

    /** @return Field<bool> */
    public static function check_incomplete(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('check_incomplete');

        return $field;
    }

    /** @return Field<bool> */
    public static function datacenter_ip(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('datacenter_ip');

        return $field;
    }

    /** @return Field<bool> */
    public static function incognito(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('incognito');

        return $field;
    }

    /** @return Field<bool> */
    public static function ip_mismatch(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('ip_mismatch');

        return $field;
    }

    /** @return Field<bool> */
    public static function javascript_disabled(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('javascript_disabled');

        return $field;
    }

    /** @return Field<bool> */
    public static function os_mismatch(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('os_mismatch');

        return $field;
    }

    /** @return Field<bool> */
    public static function os_not_detected(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('os_not_detected');

        return $field;
    }

    /** @return Field<bool> */
    public static function privacy_relay(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('privacy_relay');

        return $field;
    }

    /** @return Field<bool> */
    public static function proxy(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('proxy');

        return $field;
    }

    /** @return Field<bool> */
    public static function search_bot(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('search_bot');

        return $field;
    }

    /** @return Field<bool> */
    public static function stun_not_checked(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('stun_not_checked');

        return $field;
    }

    /** @return Field<bool> */
    public static function suspicious_paid_click(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('suspicious_paid_click');

        return $field;
    }

    /** @return Field<bool> */
    public static function timezone_mismatch(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('timezone_mismatch');

        return $field;
    }

    /** @return Field<bool> */
    public static function tor(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('tor');

        return $field;
    }

    /** @return Field<bool> */
    public static function vpn(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('vpn');

        return $field;
    }
}
