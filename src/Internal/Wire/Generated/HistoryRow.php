<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire\Generated;

use ShieldLabs\Internal\Wire\Field;

/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */
final class HistoryRow
{
    /** @return Field<string> */
    public static function browser(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('browser');

        return $field;
    }

    /** @return Field<bool> */
    public static function check_incomplete(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('check_incomplete');

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

    /** @return Field<string> */
    public static function country(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('country');

        return $field;
    }

    /** @return Field<string> */
    public static function created_at(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('created_at');

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

    /** @return Field<string> */
    public static function entry_url(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('entry_url');

        return $field;
    }

    /** @return Field<string> */
    public static function ip(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('ip');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_abuser(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_abuser');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_antidetect(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_antidetect');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_browser_automation(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_browser_automation');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_datacenter(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_datacenter');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_incognito(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_incognito');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_js_disabled(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_js_disabled');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_os_mismatch(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_os_mismatch');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_os_not_detected(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_os_not_detected');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_privacy_relay(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_privacy_relay');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_proxy(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_proxy');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_search_bot(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_search_bot');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_stun_not_checked(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_stun_not_checked');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_suspicious_paid_click(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_suspicious_paid_click');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_timezone_mismatch(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_timezone_mismatch');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_tor(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_tor');

        return $field;
    }

    /** @return Field<bool> */
    public static function is_vpn(): Field
    {
        /** @var Field<bool> $field */
        $field = new Field('is_vpn');

        return $field;
    }

    /** @return Field<string> */
    public static function os(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('os');

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
    public static function request_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('request_id');

        return $field;
    }

    /** @return Field<int> */
    public static function score(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('score');

        return $field;
    }

    /** @return Field<string> */
    public static function score_details(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('score_details');

        return $field;
    }

    /** @return Field<string> */
    public static function session_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('session_id');

        return $field;
    }

    /** @return Field<string> */
    public static function site_domain(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('site_domain');

        return $field;
    }

    /** @return Field<string> */
    public static function traffic_channel(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('traffic_channel');

        return $field;
    }

    /** @return Field<string> */
    public static function traffic_channel_group(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('traffic_channel_group');

        return $field;
    }

    /** @return Field<string> */
    public static function traffic_reason(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('traffic_reason');

        return $field;
    }

    /** @return Field<string> */
    public static function user_hid(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('user_hid');

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

    /** @return Field<int> */
    public static function ver(): Field
    {
        /** @var Field<int> $field */
        $field = new Field('ver');

        return $field;
    }

    /** @return Field<string> */
    public static function visitor_id(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('visitor_id');

        return $field;
    }

    /** @return Field<string> */
    public static function web_rtc_connection_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('web_rtc_connection_type');

        return $field;
    }

    /** @return Field<string> */
    public static function web_rtc_country(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('web_rtc_country');

        return $field;
    }

    /** @return Field<string> */
    public static function web_rtc_ip(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('web_rtc_ip');

        return $field;
    }

    /** @return Field<string> */
    public static function webrtc_leak_connection_type(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('webrtc_leak_connection_type');

        return $field;
    }

    /** @return Field<string> */
    public static function webrtc_leak_country(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('webrtc_leak_country');

        return $field;
    }

    /** @return Field<string> */
    public static function webrtc_leak_ip(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('webrtc_leak_ip');

        return $field;
    }

    /** @return Field<string> */
    public static function webrtc_leak_source(): Field
    {
        /** @var Field<string> $field */
        $field = new Field('webrtc_leak_source');

        return $field;
    }
}
