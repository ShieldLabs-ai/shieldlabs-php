<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * Known risk signal names (`signals[].name`). The set is open: new names appear
 * without an SDK release, so compare against these constants but never treat them
 * as a closed list. Branch decisions on detection flags and the Risk Score; use
 * signal names for display and logging.
 */
final class SignalName
{
    public const TOR = 'tor';
    public const JAVASCRIPT_DISABLED = 'javascript_disabled';
    public const OS_MISMATCH = 'os_mismatch';
    public const ANTIDETECT_BROWSER = 'antidetect_browser';
    public const PROXY_ROUTED_ANTIDETECT = 'proxy_routed_antidetect';
    public const PORT_SCAN_ROUTED_VIA_PROXY = 'port_scan_routed_via_proxy';
    public const BROWSER_AUTOMATION = 'browser_automation';
    public const STUN_NOT_CHECKED = 'stun_not_checked';
    public const OS_NOT_DETECTED = 'os_not_detected';
    public const BROWSER_VPN_PROXY = 'browser_vpn_proxy';
    public const VPN = 'vpn';
    public const PRIVACY_RELAY = 'privacy_relay';
    public const PROXY = 'proxy';
    public const DATACENTER_IP = 'datacenter_ip';
    public const ABUSER = 'abuser';
    public const TIMEZONE_MISMATCH = 'timezone_mismatch';
    /** Negative weight: a late network check corrected an earlier stun_not_checked. */
    public const STUN_LATE_CORRECTION = 'stun_late_correction';
    /** Marker of the rate-limit ban (weight 999), not a risk signal. */
    public const RATE_LIMITED = 'rate_limited';

    private function __construct() {}
}
