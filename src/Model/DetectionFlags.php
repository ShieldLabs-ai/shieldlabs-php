<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

use ShieldLabs\Exception\ValidationException;

/**
 * The 19 stable detection flags. A flag missing from the payload is false.
 */
final class DetectionFlags implements \JsonSerializable
{
    /** Flag names in wire order. */
    public const KEYS = [
        'vpn', 'privacy_relay', 'browser_vpn_proxy', 'tor', 'proxy', 'datacenter_ip', 'abuser',
        'os_mismatch', 'os_not_detected', 'timezone_mismatch', 'anti_detect_browser',
        'browser_automation', 'ip_mismatch', 'incognito', 'search_bot', 'suspicious_paid_click',
        'javascript_disabled', 'stun_not_checked', 'check_incomplete',
    ];

    public function __construct(
        public readonly bool $vpn = false,
        public readonly bool $privacy_relay = false,
        public readonly bool $browser_vpn_proxy = false,
        public readonly bool $tor = false,
        public readonly bool $proxy = false,
        public readonly bool $datacenter_ip = false,
        public readonly bool $abuser = false,
        public readonly bool $os_mismatch = false,
        public readonly bool $os_not_detected = false,
        public readonly bool $timezone_mismatch = false,
        public readonly bool $anti_detect_browser = false,
        public readonly bool $browser_automation = false,
        public readonly bool $ip_mismatch = false,
        public readonly bool $incognito = false,
        public readonly bool $search_bot = false,
        public readonly bool $suspicious_paid_click = false,
        public readonly bool $javascript_disabled = false,
        public readonly bool $stun_not_checked = false,
        public readonly bool $check_incomplete = false,
        public readonly ?bool $os_mismatch2 = null,
        public readonly ?bool $device_spoofing = null,
        public readonly ?bool $latency_test = null,
        public readonly ?bool $banned_ip = null,
    ) {}

    /**
     * @param array<string, bool> $flags flag name to value; missing names are false
     */
    public static function fromArray(array $flags): self
    {
        $value = static fn(string $name): bool => ($flags[$name] ?? false) === true;

        return new self(
            vpn: $value('vpn'),
            privacy_relay: $value('privacy_relay'),
            browser_vpn_proxy: $value('browser_vpn_proxy'),
            tor: $value('tor'),
            proxy: $value('proxy'),
            datacenter_ip: $value('datacenter_ip'),
            abuser: $value('abuser'),
            os_mismatch: $value('os_mismatch'),
            os_not_detected: $value('os_not_detected'),
            timezone_mismatch: $value('timezone_mismatch'),
            anti_detect_browser: $value('anti_detect_browser'),
            browser_automation: $value('browser_automation'),
            ip_mismatch: $value('ip_mismatch'),
            incognito: $value('incognito'),
            search_bot: $value('search_bot'),
            suspicious_paid_click: $value('suspicious_paid_click'),
            javascript_disabled: $value('javascript_disabled'),
            stun_not_checked: $value('stun_not_checked'),
            check_incomplete: $value('check_incomplete'),
            os_mismatch2: \array_key_exists('os_mismatch2', $flags) ? $value('os_mismatch2') : null,
            device_spoofing: \array_key_exists('device_spoofing', $flags) ? $value('device_spoofing') : null,
            latency_test: \array_key_exists('latency_test', $flags) ? $value('latency_test') : null,
            banned_ip: \array_key_exists('banned_ip', $flags) ? $value('banned_ip') : null,
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
            'privacy_relay' => $this->privacy_relay,
            'browser_vpn_proxy' => $this->browser_vpn_proxy,
            'tor' => $this->tor,
            'proxy' => $this->proxy,
            'datacenter_ip' => $this->datacenter_ip,
            'abuser' => $this->abuser,
            'os_mismatch' => $this->os_mismatch,
            'os_not_detected' => $this->os_not_detected,
            'timezone_mismatch' => $this->timezone_mismatch,
            'anti_detect_browser' => $this->anti_detect_browser,
            'browser_automation' => $this->browser_automation,
            'ip_mismatch' => $this->ip_mismatch,
            'incognito' => $this->incognito,
            'search_bot' => $this->search_bot,
            'suspicious_paid_click' => $this->suspicious_paid_click,
            'javascript_disabled' => $this->javascript_disabled,
            'stun_not_checked' => $this->stun_not_checked,
            'check_incomplete' => $this->check_incomplete,
            ...($this->os_mismatch2 !== null ? ['os_mismatch2' => $this->os_mismatch2] : []),
            ...($this->device_spoofing !== null ? ['device_spoofing' => $this->device_spoofing] : []),
            ...($this->latency_test !== null ? ['latency_test' => $this->latency_test] : []),
            ...($this->banned_ip !== null ? ['banned_ip' => $this->banned_ip] : []),
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
