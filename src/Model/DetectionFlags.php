<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

use ShieldLabs\Exception\ValidationException;

/**
 * The 20 public detection flags. A flag missing from the payload is false.
 */
final class DetectionFlags implements \JsonSerializable
{
    /** Flag names in wire order. */
    public const KEYS = [
        'vpn', 'proxy', 'privacy_relay', 'check_incomplete', 'stun_not_checked', 'os_not_detected',
        'timezone_mismatch', 'javascript_disabled', 'os_mismatch', 'ip_mismatch', 'ws_tcp_latency',
        'anti_detect_browser', 'device_spoofing', 'rate_limited', 'incognito', 'search_bot', 'ai_bot',
        'browser_automation', 'ai_browser', 'tor',
    ];

    public function __construct(
        public readonly bool $vpn = false,
        public readonly bool $proxy = false,
        public readonly bool $privacy_relay = false,
        public readonly bool $check_incomplete = false,
        public readonly bool $stun_not_checked = false,
        public readonly bool $os_not_detected = false,
        public readonly bool $timezone_mismatch = false,
        public readonly bool $javascript_disabled = false,
        public readonly bool $os_mismatch = false,
        public readonly bool $ip_mismatch = false,
        public readonly bool $ws_tcp_latency = false,
        public readonly bool $anti_detect_browser = false,
        public readonly bool $device_spoofing = false,
        public readonly bool $rate_limited = false,
        public readonly bool $incognito = false,
        public readonly bool $search_bot = false,
        public readonly bool $ai_bot = false,
        public readonly bool $browser_automation = false,
        public readonly bool $ai_browser = false,
        public readonly bool $tor = false,
    ) {}

    /**
     * @param array<string, bool> $flags flag name to value; missing names are false
     */
    public static function fromArray(array $flags): self
    {
        $value = static fn(string $name): bool => ($flags[$name] ?? false) === true;

        return new self(
            vpn: $value('vpn'),
            proxy: $value('proxy'),
            privacy_relay: $value('privacy_relay'),
            check_incomplete: $value('check_incomplete'),
            stun_not_checked: $value('stun_not_checked'),
            os_not_detected: $value('os_not_detected'),
            timezone_mismatch: $value('timezone_mismatch'),
            javascript_disabled: $value('javascript_disabled'),
            os_mismatch: $value('os_mismatch'),
            ip_mismatch: $value('ip_mismatch'),
            ws_tcp_latency: $value('ws_tcp_latency'),
            anti_detect_browser: $value('anti_detect_browser'),
            device_spoofing: $value('device_spoofing'),
            rate_limited: $value('rate_limited'),
            incognito: $value('incognito'),
            search_bot: $value('search_bot'),
            ai_bot: $value('ai_bot'),
            browser_automation: $value('browser_automation'),
            ai_browser: $value('ai_browser'),
            tor: $value('tor'),
        );
    }

    /**
     * Value of one flag by name.
     *
     * @throws ValidationException when the name is not one of {@see self::KEYS}
     */
    public function get(string $name): bool
    {
        $flags = $this->toArray();
        if (!\array_key_exists($name, $flags)) {
            throw new ValidationException(\sprintf('Unknown detection flag "%s". Known flags: %s.', $name, implode(', ', self::KEYS)));
        }

        return $flags[$name];
    }

    /**
     * Names of the flags that are true, in wire order.
     *
     * @return list<string>
     */
    public function active(): array
    {
        return array_keys(array_filter($this->toArray()));
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'vpn' => $this->vpn,
            'proxy' => $this->proxy,
            'privacy_relay' => $this->privacy_relay,
            'check_incomplete' => $this->check_incomplete,
            'stun_not_checked' => $this->stun_not_checked,
            'os_not_detected' => $this->os_not_detected,
            'timezone_mismatch' => $this->timezone_mismatch,
            'javascript_disabled' => $this->javascript_disabled,
            'os_mismatch' => $this->os_mismatch,
            'ip_mismatch' => $this->ip_mismatch,
            'ws_tcp_latency' => $this->ws_tcp_latency,
            'anti_detect_browser' => $this->anti_detect_browser,
            'device_spoofing' => $this->device_spoofing,
            'rate_limited' => $this->rate_limited,
            'incognito' => $this->incognito,
            'search_bot' => $this->search_bot,
            'ai_bot' => $this->ai_bot,
            'browser_automation' => $this->browser_automation,
            'ai_browser' => $this->ai_browser,
            'tor' => $this->tor,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
