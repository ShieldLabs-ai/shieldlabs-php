<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

/**
 * Turns a History API row or webhook `data` object into the shared Identification
 * shape. Every ShieldLabs server SDK applies these exact rules, and the shared
 * test fixtures in tests/data check them.
 *
 * The returned arrays hold plain values; `observed_at` is a UTC DateTimeImmutable
 * (or null when the timestamp cannot be parsed).
 *
 * @internal
 */
final class Normalizer
{
    /** The 19 detection flags, in wire order. */
    public const FLAG_KEYS = [
        'vpn', 'privacy_relay', 'browser_vpn_proxy', 'tor', 'proxy', 'datacenter_ip', 'abuser',
        'os_mismatch', 'os_not_detected', 'timezone_mismatch', 'anti_detect_browser',
        'browser_automation', 'ip_mismatch', 'incognito', 'search_bot', 'suspicious_paid_click',
        'javascript_disabled', 'stun_not_checked', 'check_incomplete',
    ];

    public const TRAFFIC_KEYS = [
        'channel', 'referrer_domain', 'landing_url', 'click_id_type',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    ];

    /** Normalized flag name to History row column. */
    private const HISTORY_FLAG_MAP = [
        'vpn' => 'is_vpn',
        'privacy_relay' => 'is_privacy_relay',
        'tor' => 'is_tor',
        'proxy' => 'is_proxy',
        'datacenter_ip' => 'is_datacenter',
        'abuser' => 'is_abuser',
        'os_mismatch' => 'is_os_mismatch',
        'os_not_detected' => 'is_os_not_detected',
        'timezone_mismatch' => 'is_timezone_mismatch',
        'anti_detect_browser' => 'is_antidetect',
        'browser_automation' => 'is_browser_automation',
        'incognito' => 'is_incognito',
        'search_bot' => 'is_search_bot',
        'suspicious_paid_click' => 'is_suspicious_paid_click',
        'javascript_disabled' => 'is_js_disabled',
        'stun_not_checked' => 'is_stun_not_checked',
        'check_incomplete' => 'check_incomplete',
    ];

    private const EXACT_SLUGS = [
        'Is tor' => 'tor',
        'Is VPN' => 'vpn',
        'Is privacy relay' => 'privacy_relay',
        'Is proxy' => 'proxy',
        'Is datacenter' => 'datacenter_ip',
        'Is abuser' => 'abuser',
        'Stun is not checked' => 'stun_not_checked',
        'Stun passed (late arrival, corrected)' => 'stun_late_correction',
        'UA OS is not detected' => 'os_not_detected',
        'Network OS is not detected' => 'os_not_detected',
        'Browser timezone ≠ IP-timezone' => 'timezone_mismatch',
        'Browser VPN/Proxy' => 'browser_vpn_proxy',
        'Browser Automation' => 'browser_automation',
        'Port scan routed via proxy (antidetect browser pattern)' => 'proxy_routed_antidetect',
        'User has been banned 1H, to many requests' => 'rate_limited',
    ];

    private const PREFIX_SLUGS = [
        ['Antidetect browser', 'antidetect_browser'],
        ['Os_mismatch', 'os_mismatch'],
        ['OS mismatch2', 'os_mismatch2'],
        ['TCP handshake', 'tcp_handshake_v2'],
        ['Latency test', 'ws_tcp_latency'],
        ['JavaScript disabled', 'javascript_disabled'],
    ];

    private const STICKY_PREFIX = 'Sticky verdict: ';
    private const IP_LEAK_PREFIX = 'IP ≠ leakIP';

    /** Characters treated as whitespace when trimming (the Unicode whitespace set). */
    private const WHITESPACE = '[\x{09}-\x{0D}\x{1C}-\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    private function __construct() {}

    /**
     * Signal slug for a History `score_details` description (the name the webhook
     * would carry): exact map, then known prefixes, then sticky verdicts, then the
     * generic slugger.
     */
    public static function signalSlug(string $description): string
    {
        if (isset(self::EXACT_SLUGS[$description])) {
            return self::EXACT_SLUGS[$description];
        }
        foreach (self::PREFIX_SLUGS as [$prefix, $slug]) {
            if (str_starts_with($description, $prefix)) {
                return $slug;
            }
        }
        if (str_starts_with($description, self::STICKY_PREFIX)) {
            $index = strpos($description, ':');
            $rest = $index !== false && $index + 1 < \strlen($description)
                ? self::strip(substr($description, $index + 1))
                : $description;

            return self::fallbackSlug($rest);
        }

        return self::fallbackSlug($description);
    }

    /**
     * Generic slugger: text before the first "(", lowercased letters and digits,
     * runs of spaces, "-" and "/" collapsed to "_", "≠" written as "_neq_", other
     * characters dropped; "unknown" when nothing remains.
     */
    public static function fallbackSlug(string $description): string
    {
        $text = self::strip($description);
        $paren = strpos($text, '(');
        if ($paren !== false) {
            $text = self::strip(substr($text, 0, $paren));
        }
        $out = '';
        $previousWasSeparator = false;
        foreach (self::characters($text) as $char) {
            if ($char === ' ' || $char === '-' || $char === '/') {
                if (!$previousWasSeparator && $out !== '') {
                    $out .= '_';
                    $previousWasSeparator = true;
                }
            } elseif ($char === '≠') {
                $out .= '_neq_';
                $previousWasSeparator = false;
            } elseif (preg_match('/^[\p{L}\p{Nd}]$/u', $char) === 1) {
                $out .= self::lower($char);
                $previousWasSeparator = false;
            }
        }
        $slug = trim($out, '_');

        return $slug !== '' ? $slug : 'unknown';
    }

    /**
     * History `created_at` ("YYYY-MM-DD HH:MM:SS[.fff]", UTC, no zone designator).
     * A zone designator, when present, is ignored: History times are always UTC.
     */
    public static function parseHistoryTime(mixed $value): ?\DateTimeImmutable
    {
        $text = self::strip(self::orEmpty($value));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:?\d{2})?$/', $text, $m) !== 1) {
            return null;
        }

        return Time::build($m[1], $m[2], $m[3] ?? '', '+00:00');
    }

    /**
     * RFC 3339 timestamp with up to 9 fractional digits (kept to microseconds by
     * truncation), converted to UTC.
     */
    public static function parseRfc3339(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return Time::build($m[1], $m[2], $m[3], $m[4] === 'Z' ? '+00:00' : $m[4]);
    }

    /**
     * @param array<mixed> $row one element of a History API response `data` array
     *
     * @return array{
     *     request_id: string, visitor_id: string, device_id: string, session_id: string, cookie_id: string,
     *     user_hid: string|null, domain: string,
     *     public_ip: array{ip: string, country: string}, local_ip: array{ip: string, country: string},
     *     connection_type: string, os: string, browser: string, device_type: string,
     *     traffic_source: array<string, string>, risk_score: int,
     *     signals: list<array{name: string, weight: int, description: string|null}>,
     *     detection_flags: array<string, bool>, observed_at: \DateTimeImmutable|null, source: string
     * }
     */
    public static function fromHistoryRow(array $row): array
    {
        $leakSource = self::strip(self::orEmpty($row['webrtc_leak_source'] ?? null));
        if ($leakSource !== '' && $leakSource !== 'none') {
            $localIp = self::ip($row['webrtc_leak_ip'] ?? null);
            $localCountry = self::orEmpty($row['webrtc_leak_country'] ?? null);
        } else {
            $localIp = self::ip($row['web_rtc_ip'] ?? null);
            $localCountry = self::orEmpty($row['web_rtc_country'] ?? null);
        }
        $publicIp = self::ip($row['ip'] ?? null);

        $signals = [];
        $ipLeakDetail = false;
        foreach (self::scoreDetails($row['score_details'] ?? null) as $detail) {
            if (!self::isObject($detail)) {
                continue;
            }
            $description = self::orEmpty($detail['Description'] ?? null);
            if (str_starts_with($description, self::IP_LEAK_PREFIX)) {
                $ipLeakDetail = true;
            }
            $weight = $detail['Value'] ?? 0;
            if (!\is_int($weight) || $weight === 0) {
                continue;
            }
            $signals[] = [
                'name' => self::signalSlug($description),
                'weight' => $weight,
                'description' => $description,
            ];
        }

        $searchBot = self::truthy($row['is_search_bot'] ?? null);
        $flags = [];
        foreach (self::FLAG_KEYS as $key) {
            if ($key === 'browser_vpn_proxy') {
                $flags[$key] = ($row['connection_type'] ?? null) === 'browser_vpn_proxy';
            } elseif ($key === 'ip_mismatch') {
                $flags[$key] = !$searchBot
                    && ($ipLeakDetail || ($publicIp !== '' && $localIp !== '' && $publicIp !== $localIp));
            } else {
                $flags[$key] = self::truthy($row[self::HISTORY_FLAG_MAP[$key]] ?? null);
            }
        }

        $siteDomain = $row['site_domain'] ?? null;

        return [
            'request_id' => self::str($row['request_id'] ?? null),
            'visitor_id' => self::str($row['visitor_id'] ?? null),
            'device_id' => self::str($row['device_id'] ?? null),
            'session_id' => self::str($row['session_id'] ?? null),
            'cookie_id' => self::str($row['cookie_id'] ?? null),
            'user_hid' => self::userHid($row['user_hid'] ?? null),
            'domain' => self::truthy($siteDomain) ? self::str($siteDomain) : self::str($row['domain'] ?? null),
            'public_ip' => ['ip' => $publicIp, 'country' => self::orEmpty($row['country'] ?? null)],
            'local_ip' => ['ip' => $localIp, 'country' => $localCountry],
            'connection_type' => self::str($row['connection_type'] ?? null),
            'os' => self::str($row['os'] ?? null),
            'browser' => self::str($row['browser'] ?? null),
            'device_type' => self::str($row['device_type'] ?? null),
            'traffic_source' => [
                'channel' => self::orEmpty($row['traffic_channel'] ?? null),
                'referrer_domain' => self::orEmpty($row['referrer_domain'] ?? null),
                'landing_url' => self::orEmpty($row['entry_url'] ?? null),
                'click_id_type' => self::orEmpty($row['click_id_type'] ?? null),
                'utm_source' => self::orEmpty($row['utm_source'] ?? null),
                'utm_medium' => self::orEmpty($row['utm_medium'] ?? null),
                'utm_campaign' => self::orEmpty($row['utm_campaign'] ?? null),
                'utm_content' => self::orEmpty($row['utm_content'] ?? null),
                'utm_term' => self::orEmpty($row['utm_term'] ?? null),
            ],
            'risk_score' => self::int($row['score'] ?? null),
            'signals' => $signals,
            'detection_flags' => $flags,
            'observed_at' => self::parseHistoryTime($row['created_at'] ?? null),
            'source' => 'history',
        ];
    }

    /**
     * @param array<mixed> $data the `data` object of an identification.scored webhook
     *
     * @return array{
     *     request_id: string, visitor_id: string, device_id: string, session_id: string, cookie_id: string,
     *     user_hid: string|null, domain: string,
     *     public_ip: array{ip: string, country: string}, local_ip: array{ip: string, country: string},
     *     connection_type: string, os: string, browser: string, device_type: string,
     *     traffic_source: array<string, string>, risk_score: int,
     *     signals: list<array{name: string, weight: int, description: string|null}>,
     *     detection_flags: array<string, bool>, observed_at: \DateTimeImmutable|null, source: string
     * }
     */
    public static function fromWebhookData(array $data): array
    {
        $rawFlags = $data['detection_flags'] ?? null;
        $rawFlags = \is_array($rawFlags) ? $rawFlags : [];
        $flags = [];
        foreach (self::FLAG_KEYS as $key) {
            $flags[$key] = self::truthy($rawFlags[$key] ?? null);
        }

        $traffic = $data['traffic_source'] ?? null;
        $traffic = \is_array($traffic) ? $traffic : [];
        $trafficSource = [];
        foreach (self::TRAFFIC_KEYS as $key) {
            $trafficSource[$key] = self::orEmpty($traffic[$key] ?? null);
        }

        $signals = [];
        $rawSignals = $data['signals'] ?? null;
        if (\is_array($rawSignals) && array_is_list($rawSignals)) {
            foreach ($rawSignals as $signal) {
                if (!self::isObject($signal)) {
                    continue;
                }
                $signals[] = [
                    'name' => self::str($signal['name'] ?? null),
                    'weight' => self::int($signal['weight'] ?? null),
                    'description' => null,
                ];
            }
        }

        return [
            'request_id' => self::str($data['request_id'] ?? null),
            'visitor_id' => self::str($data['visitor_id'] ?? null),
            'device_id' => self::str($data['device_id'] ?? null),
            'session_id' => self::str($data['session_id'] ?? null),
            'cookie_id' => self::str($data['cookie_id'] ?? null),
            'user_hid' => self::userHid($data['user_hid'] ?? null),
            'domain' => self::str($data['domain'] ?? null),
            'public_ip' => self::ipObject($data['public_ip'] ?? null),
            'local_ip' => self::ipObject($data['local_ip'] ?? null),
            'connection_type' => self::str($data['connection_type'] ?? null),
            'os' => self::str($data['os'] ?? null),
            'browser' => self::str($data['browser'] ?? null),
            'device_type' => self::str($data['device_type'] ?? null),
            'traffic_source' => $trafficSource,
            'risk_score' => self::int($data['risk_score'] ?? null),
            'signals' => $signals,
            'detection_flags' => $flags,
            'observed_at' => self::parseRfc3339($data['observed_at'] ?? null),
            'source' => 'webhook',
        ];
    }

    /**
     * Trims Unicode whitespace from both ends.
     */
    public static function strip(string $value): string
    {
        $result = preg_replace('/^' . self::WHITESPACE . '+|' . self::WHITESPACE . '+$/u', '', $value);

        return $result ?? trim($value);
    }

    /**
     * @return array{ip: string, country: string}
     */
    private static function ipObject(mixed $value): array
    {
        $object = \is_array($value) ? $value : [];

        return [
            'ip' => self::ip($object['ip'] ?? null),
            'country' => self::orEmpty($object['country'] ?? null),
        ];
    }

    /**
     * "0.0.0.0" and blank values mean "no address" and become "".
     */
    private static function ip(mixed $value): string
    {
        $ip = self::strip(self::orEmpty($value));

        return $ip === '' || $ip === '0.0.0.0' ? '' : $ip;
    }

    /**
     * `score_details` is a JSON-encoded string; anything else (or invalid JSON, or a
     * value that is not a list) yields no details.
     *
     * @return list<mixed>
     */
    private static function scoreDetails(mixed $value): array
    {
        if (!\is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    private static function userHid(mixed $value): ?string
    {
        $userHid = self::str($value);

        return $userHid === '' ? null : $userHid;
    }

    /**
     * True for decoded JSON objects (an empty array can be either; it carries no data).
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return \is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * JSON truthiness: false, null, 0, "" and empty containers are false.
     */
    private static function truthy(mixed $value): bool
    {
        return \is_string($value) ? $value !== '' : (bool) $value;
    }

    /**
     * The value when it is truthy, otherwise "" (converted to a string).
     */
    private static function orEmpty(mixed $value): string
    {
        return self::truthy($value) ? self::str($value) : '';
    }

    private static function str(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && is_finite($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function characters(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $characters = preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);

        return $characters !== false ? $characters : str_split($text);
    }

    private static function lower(string $char): string
    {
        if (\function_exists('mb_strtolower')) {
            return mb_strtolower($char, 'UTF-8');
        }

        return strtr($char, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
