<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

use ShieldLabs\Internal\Wire\Field;
use ShieldLabs\Internal\Wire\Generated\DetectionFlags;
use ShieldLabs\Internal\Wire\Generated\HistoryRow;
use ShieldLabs\Internal\Wire\Generated\IpInfo;
use ShieldLabs\Internal\Wire\Generated\LocalIpInfo;
use ShieldLabs\Internal\Wire\Generated\ScoredData;
use ShieldLabs\Internal\Wire\Generated\ScoreDetail;
use ShieldLabs\Internal\Wire\Generated\Signal;
use ShieldLabs\Internal\Wire\Generated\TrafficSource;
use ShieldLabs\Internal\Wire\Read;

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

    /**
     * Normalized flag name to History row column.
     *
     * @return array<string, Field<bool>>
     */
    private static function historyFlagMap(): array
    {
        return [
            'vpn' => HistoryRow::is_vpn(),
            'privacy_relay' => HistoryRow::is_privacy_relay(),
            'tor' => HistoryRow::is_tor(),
            'proxy' => HistoryRow::is_proxy(),
            'datacenter_ip' => HistoryRow::is_datacenter(),
            'abuser' => HistoryRow::is_abuser(),
            'os_mismatch' => HistoryRow::is_os_mismatch(),
            'os_not_detected' => HistoryRow::is_os_not_detected(),
            'timezone_mismatch' => HistoryRow::is_timezone_mismatch(),
            'anti_detect_browser' => HistoryRow::is_antidetect(),
            'browser_automation' => HistoryRow::is_browser_automation(),
            'incognito' => HistoryRow::is_incognito(),
            'search_bot' => HistoryRow::is_search_bot(),
            'suspicious_paid_click' => HistoryRow::is_suspicious_paid_click(),
            'javascript_disabled' => HistoryRow::is_js_disabled(),
            'stun_not_checked' => HistoryRow::is_stun_not_checked(),
            'check_incomplete' => HistoryRow::check_incomplete(),
        ];
    }

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
        $leakSource = self::strip(self::orEmpty(Read::text(HistoryRow::webrtc_leak_source(), $row)));
        if ($leakSource !== '' && $leakSource !== 'none') {
            $localIp = self::ip(Read::text(HistoryRow::webrtc_leak_ip(), $row));
            $localCountry = self::orEmpty(Read::text(HistoryRow::webrtc_leak_country(), $row));
        } else {
            $localIp = self::ip(Read::text(HistoryRow::web_rtc_ip(), $row));
            $localCountry = self::orEmpty(Read::text(HistoryRow::web_rtc_country(), $row));
        }
        $publicIp = self::ip(Read::text(HistoryRow::ip(), $row));

        $signals = [];
        $ipLeakDetail = false;
        foreach (self::scoreDetails(Read::text(HistoryRow::score_details(), $row)) as $detail) {
            if (!self::isObject($detail)) {
                continue;
            }
            $description = self::orEmpty(Read::text(ScoreDetail::Description(), $detail));
            if (str_starts_with($description, self::IP_LEAK_PREFIX)) {
                $ipLeakDetail = true;
            }
            $weight = Read::integer(ScoreDetail::Value(), $detail) ?? 0;
            if (!\is_int($weight) || $weight === 0) {
                continue;
            }
            $signals[] = [
                'name' => self::signalSlug($description),
                'weight' => $weight,
                'description' => $description,
            ];
        }

        $historyFlagMap = self::historyFlagMap();
        $searchBot = self::truthy(Read::boolean(HistoryRow::is_search_bot(), $row));
        $flags = [];
        foreach (self::FLAG_KEYS as $key) {
            if ($key === 'browser_vpn_proxy') {
                $flags[$key] = Read::text(HistoryRow::connection_type(), $row) === 'browser_vpn_proxy';
            } elseif ($key === 'ip_mismatch') {
                $flags[$key] = !$searchBot
                    && ($ipLeakDetail || ($publicIp !== '' && $localIp !== '' && $publicIp !== $localIp));
            } else {
                $flags[$key] = self::truthy(Read::boolean($historyFlagMap[$key], $row));
            }
        }

        $siteDomain = Read::text(HistoryRow::site_domain(), $row);

        return [
            'request_id' => self::str(Read::text(HistoryRow::request_id(), $row)),
            'visitor_id' => self::str(Read::text(HistoryRow::visitor_id(), $row)),
            'device_id' => self::str(Read::text(HistoryRow::device_id(), $row)),
            'session_id' => self::str(Read::text(HistoryRow::session_id(), $row)),
            'cookie_id' => self::str(Read::text(HistoryRow::cookie_id(), $row)),
            'user_hid' => self::userHid(Read::text(HistoryRow::user_hid(), $row)),
            'domain' => self::truthy($siteDomain) ? self::str($siteDomain) : self::str(Read::text(HistoryRow::domain(), $row)),
            'public_ip' => ['ip' => $publicIp, 'country' => self::orEmpty(Read::text(HistoryRow::country(), $row))],
            'local_ip' => ['ip' => $localIp, 'country' => $localCountry],
            'connection_type' => self::str(Read::text(HistoryRow::connection_type(), $row)),
            'os' => self::str(Read::text(HistoryRow::os(), $row)),
            'browser' => self::str(Read::text(HistoryRow::browser(), $row)),
            'device_type' => self::str(Read::text(HistoryRow::device_type(), $row)),
            'traffic_source' => [
                'channel' => self::orEmpty(Read::text(HistoryRow::traffic_channel(), $row)),
                'referrer_domain' => self::orEmpty(Read::text(HistoryRow::referrer_domain(), $row)),
                'landing_url' => self::orEmpty(Read::text(HistoryRow::entry_url(), $row)),
                'click_id_type' => self::orEmpty(Read::text(HistoryRow::click_id_type(), $row)),
                'utm_source' => self::orEmpty(Read::text(HistoryRow::utm_source(), $row)),
                'utm_medium' => self::orEmpty(Read::text(HistoryRow::utm_medium(), $row)),
                'utm_campaign' => self::orEmpty(Read::text(HistoryRow::utm_campaign(), $row)),
                'utm_content' => self::orEmpty(Read::text(HistoryRow::utm_content(), $row)),
                'utm_term' => self::orEmpty(Read::text(HistoryRow::utm_term(), $row)),
            ],
            'risk_score' => self::int(Read::integer(HistoryRow::score(), $row)),
            'signals' => $signals,
            'detection_flags' => $flags,
            'observed_at' => self::parseHistoryTime(Read::text(HistoryRow::created_at(), $row)),
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
        $rawFlags = Read::collection(ScoredData::detection_flags(), $data);
        $rawFlags = \is_array($rawFlags) ? $rawFlags : [];
        $flags = [];
        foreach ([
            'vpn' => DetectionFlags::vpn(),
            'privacy_relay' => DetectionFlags::privacy_relay(),
            'browser_vpn_proxy' => DetectionFlags::browser_vpn_proxy(),
            'tor' => DetectionFlags::tor(),
            'proxy' => DetectionFlags::proxy(),
            'datacenter_ip' => DetectionFlags::datacenter_ip(),
            'abuser' => DetectionFlags::abuser(),
            'os_mismatch' => DetectionFlags::os_mismatch(),
            'os_not_detected' => DetectionFlags::os_not_detected(),
            'timezone_mismatch' => DetectionFlags::timezone_mismatch(),
            'anti_detect_browser' => DetectionFlags::anti_detect_browser(),
            'browser_automation' => DetectionFlags::browser_automation(),
            'ip_mismatch' => DetectionFlags::ip_mismatch(),
            'incognito' => DetectionFlags::incognito(),
            'search_bot' => DetectionFlags::search_bot(),
            'suspicious_paid_click' => DetectionFlags::suspicious_paid_click(),
            'javascript_disabled' => DetectionFlags::javascript_disabled(),
            'stun_not_checked' => DetectionFlags::stun_not_checked(),
            'check_incomplete' => DetectionFlags::check_incomplete(),
        ] as $key => $field) {
            $flags[$key] = self::truthy(Read::boolean($field, $rawFlags));
        }

        $traffic = Read::collection(ScoredData::traffic_source(), $data);
        $traffic = \is_array($traffic) ? $traffic : [];
        $trafficSource = [];
        foreach ([
            'channel' => TrafficSource::channel(),
            'referrer_domain' => TrafficSource::referrer_domain(),
            'landing_url' => TrafficSource::landing_url(),
            'click_id_type' => TrafficSource::click_id_type(),
            'utm_source' => TrafficSource::utm_source(),
            'utm_medium' => TrafficSource::utm_medium(),
            'utm_campaign' => TrafficSource::utm_campaign(),
            'utm_content' => TrafficSource::utm_content(),
            'utm_term' => TrafficSource::utm_term(),
        ] as $key => $field) {
            $trafficSource[$key] = self::orEmpty(Read::text($field, $traffic));
        }

        $signals = [];
        $rawSignals = Read::collection(ScoredData::signals(), $data);
        if (\is_array($rawSignals) && array_is_list($rawSignals)) {
            foreach ($rawSignals as $signal) {
                if (!self::isObject($signal)) {
                    continue;
                }
                $signals[] = [
                    'name' => self::str(Read::text(Signal::name(), $signal)),
                    'weight' => self::int(Read::integer(Signal::weight(), $signal)),
                    'description' => null,
                ];
            }
        }

        return [
            'request_id' => self::str(Read::text(ScoredData::request_id(), $data)),
            'visitor_id' => self::str(Read::text(ScoredData::visitor_id(), $data)),
            'device_id' => self::str(Read::text(ScoredData::device_id(), $data)),
            'session_id' => self::str(Read::text(ScoredData::session_id(), $data)),
            'cookie_id' => self::str(Read::text(ScoredData::cookie_id(), $data)),
            'user_hid' => self::userHid(Read::text(ScoredData::user_hid(), $data)),
            'domain' => self::str(Read::text(ScoredData::domain(), $data)),
            'public_ip' => self::ipObject(Read::collection(ScoredData::public_ip(), $data), IpInfo::ip(), IpInfo::country()),
            'local_ip' => self::ipObject(Read::collection(ScoredData::local_ip(), $data), LocalIpInfo::ip(), LocalIpInfo::country()),
            'connection_type' => self::str(Read::text(ScoredData::connection_type(), $data)),
            'os' => self::str(Read::text(ScoredData::os(), $data)),
            'browser' => self::str(Read::text(ScoredData::browser(), $data)),
            'device_type' => self::str(Read::text(ScoredData::device_type(), $data)),
            'traffic_source' => $trafficSource,
            'risk_score' => self::int(Read::integer(ScoredData::risk_score(), $data)),
            'signals' => $signals,
            'detection_flags' => $flags,
            'observed_at' => self::parseRfc3339(Read::text(ScoredData::observed_at(), $data)),
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
     * @param Field<string> $ipField
     * @param Field<string> $countryField
     *
     * @return array{ip: string, country: string}
     */
    private static function ipObject(mixed $value, Field $ipField, Field $countryField): array
    {
        $object = \is_array($value) ? $value : [];

        return [
            'ip' => self::ip(Read::text($ipField, $object)),
            'country' => self::orEmpty(Read::text($countryField, $object)),
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
