<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * Identifier to search the History API by.
 */
enum LookupType: string
{
    /** Dotted IPv4 address of the visitor. */
    case Ip = 'ip';
    /** Your hashed or pseudonymous account id, sent exactly as given. */
    case UserHid = 'user_hid';
    case VisitorId = 'visitor_id';
    case RequestId = 'request_id';
    case DeviceId = 'device_id';
    case SessionId = 'session_id';
    case CookieId = 'cookie_id';

    /**
     * True for the identifiers that are UUIDs (all except ip and user_hid).
     */
    public function isUuid(): bool
    {
        return match ($this) {
            self::Ip, self::UserHid => false,
            default => true,
        };
    }
}
