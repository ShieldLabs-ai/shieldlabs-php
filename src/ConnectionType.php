<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * Known `connection_type` values. Unknown strings are kept as they arrive.
 */
final class ConnectionType
{
    public const DIRECT = 'direct';
    public const MOBILE = 'mobile';
    public const VPN = 'vpn';
    public const PROXY = 'proxy';
    public const TOR = 'tor';
    public const PRIVACY_RELAY = 'privacy_relay';
    public const BROWSER_VPN_PROXY = 'browser_vpn_proxy';
    public const UNKNOWN = 'unknown';

    private function __construct() {}
}
