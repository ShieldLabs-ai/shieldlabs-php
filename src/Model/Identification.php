<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

use ShieldLabs\Internal\Normalizer;
use ShieldLabs\Internal\Time;
use ShieldLabs\RiskBand;

/**
 * One identification (one run of the ShieldLabs agent in one browser), with the
 * same shape whether it came from a webhook or from a History API row. Property
 * names follow the webhook payload.
 */
final class Identification implements \JsonSerializable
{
    /** The all-zero UUID. As a device ID it means "no usable device signals". */
    public const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_HISTORY = 'history';

    public readonly ?string $result_version;
    public readonly ?string $scoring_version;
    /** @var array<mixed>|null */
    public readonly ?array $risk_events;
    /** @var array<mixed>|null */
    public readonly ?array $hre;
    /** @var array<mixed>|null */
    public readonly ?array $fingerprint;

    /**
     * @param string                  $request_id      UUID created in the browser for this identification
     * @param string                  $visitor_id      server-side visitor ID (sticky to the device)
     * @param string                  $device_id       server-side device ID; {@see self::NIL_UUID} when there were no usable device signals
     * @param string                  $session_id      one visit on one origin
     * @param string                  $cookie_id       first-party browser ID kept by the agent
     * @param string|null             $user_hid        your User HID as sent by the browser, "anonymous" for anonymous checks, null when empty
     * @param string                  $domain          registered domain the identification belongs to
     * @param string                  $connection_type see {@see \ShieldLabs\ConnectionType}; unknown values are kept
     * @param int                     $risk_score      0-100, or 999 as the rate-limit marker
     * @param list<Signal>            $signals         weighted risk signals behind the score
     * @param \DateTimeImmutable|null $observed_at     UTC time of the identification (null only when the payload had no parsable time)
     * @param string                  $source          {@see self::SOURCE_WEBHOOK} or {@see self::SOURCE_HISTORY}
     * @param array<mixed>            $raw             the payload as received (webhook `data` or the History row)
     */
    public function __construct(
        public readonly string $request_id,
        public readonly string $visitor_id,
        public readonly string $device_id,
        public readonly string $session_id,
        public readonly string $cookie_id,
        public readonly ?string $user_hid,
        public readonly string $domain,
        public readonly IpInfo $public_ip,
        public readonly IpInfo $local_ip,
        public readonly string $connection_type,
        public readonly string $os,
        public readonly string $browser,
        public readonly string $device_type,
        public readonly TrafficSource $traffic_source,
        public readonly int $risk_score,
        public readonly array $signals,
        public readonly DetectionFlags $detection_flags,
        public readonly ?\DateTimeImmutable $observed_at,
        public readonly string $source,
        public readonly array $raw = [],
    ) {
        $this->result_version = \is_string($raw['result_version'] ?? null) ? $raw['result_version'] : null;
        $this->scoring_version = \is_string($raw['scoring_version'] ?? null) ? $raw['scoring_version'] : null;
        $this->risk_events = \is_array($raw['risk_events'] ?? null) ? $raw['risk_events'] : null;
        $this->hre = \is_array($raw['hre'] ?? null) ? $raw['hre'] : null;
        $this->fingerprint = \is_array($raw['fingerprint'] ?? null) ? $raw['fingerprint'] : null;
    }

    /**
     * Builds an identification from one History API row.
     *
     * @param array<mixed> $row
     */
    public static function fromHistoryRow(array $row): self
    {
        return self::fromNormalized(Normalizer::fromHistoryRow($row), $row);
    }

    /**
     * Builds an identification from the `data` object of an identification.scored
     * webhook. Missing detection flags are false.
     *
     * @param array<mixed> $data
     */
    public static function fromWebhookData(array $data): self
    {
        return self::fromNormalized(Normalizer::fromWebhookData($data), $data);
    }

    /**
     * Band of the Risk Score ({@see RiskBand::RateLimited} for the 999 marker).
     */
    public function riskBand(): RiskBand
    {
        return RiskBand::fromScore($this->risk_score);
    }

    /**
     * True when the score is the rate-limit marker (above 100), never a real score.
     */
    public function isRateLimited(): bool
    {
        return $this->risk_score > 100;
    }

    /**
     * False when the device ID is the all-zero UUID (or empty): no usable device signals.
     */
    public function hasDeviceSignals(): bool
    {
        return $this->device_id !== '' && $this->device_id !== self::NIL_UUID;
    }

    /**
     * The normalized identification as plain values (without `raw`); `observed_at`
     * is RFC 3339 in UTC with milliseconds.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->request_id,
            'visitor_id' => $this->visitor_id,
            'device_id' => $this->device_id,
            'session_id' => $this->session_id,
            'cookie_id' => $this->cookie_id,
            'user_hid' => $this->user_hid,
            'domain' => $this->domain,
            'public_ip' => $this->public_ip->toArray(),
            'local_ip' => $this->local_ip->toArray(),
            'connection_type' => $this->connection_type,
            'os' => $this->os,
            'browser' => $this->browser,
            'device_type' => $this->device_type,
            'traffic_source' => $this->traffic_source->toArray(),
            'risk_score' => $this->risk_score,
            'signals' => array_map(static fn(Signal $signal): array => $signal->toArray(), $this->signals),
            'detection_flags' => $this->detection_flags->toArray(),
            'observed_at' => Time::format($this->observed_at),
            'source' => $this->source,
            ...($this->result_version !== null ? ['result_version' => $this->result_version] : []),
            ...($this->scoring_version !== null ? ['scoring_version' => $this->scoring_version] : []),
            ...($this->risk_events !== null ? ['risk_events' => $this->risk_events] : []),
            ...($this->hre !== null ? ['hre' => $this->hre] : []),
            ...($this->fingerprint !== null ? ['fingerprint' => $this->fingerprint] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array{
     *     request_id: string, visitor_id: string, device_id: string, session_id: string, cookie_id: string,
     *     user_hid: string|null, domain: string,
     *     public_ip: array{ip: string, country: string}, local_ip: array{ip: string, country: string},
     *     connection_type: string, os: string, browser: string, device_type: string,
     *     traffic_source: array<string, string>, risk_score: int,
     *     signals: list<array{name: string, weight: int, description: string|null}>,
     *     detection_flags: array<string, bool>, observed_at: \DateTimeImmutable|null, source: string
     * } $normalized
     * @param array<mixed> $raw
     */
    private static function fromNormalized(array $normalized, array $raw): self
    {
        $traffic = $normalized['traffic_source'];

        return new self(
            request_id: $normalized['request_id'],
            visitor_id: $normalized['visitor_id'],
            device_id: $normalized['device_id'],
            session_id: $normalized['session_id'],
            cookie_id: $normalized['cookie_id'],
            user_hid: $normalized['user_hid'],
            domain: $normalized['domain'],
            public_ip: new IpInfo($normalized['public_ip']['ip'], $normalized['public_ip']['country']),
            local_ip: new IpInfo($normalized['local_ip']['ip'], $normalized['local_ip']['country']),
            connection_type: $normalized['connection_type'],
            os: $normalized['os'],
            browser: $normalized['browser'],
            device_type: $normalized['device_type'],
            traffic_source: new TrafficSource(
                channel: $traffic['channel'] ?? '',
                referrer_domain: $traffic['referrer_domain'] ?? '',
                landing_url: $traffic['landing_url'] ?? '',
                click_id_type: $traffic['click_id_type'] ?? '',
                utm_source: $traffic['utm_source'] ?? '',
                utm_medium: $traffic['utm_medium'] ?? '',
                utm_campaign: $traffic['utm_campaign'] ?? '',
                utm_content: $traffic['utm_content'] ?? '',
                utm_term: $traffic['utm_term'] ?? '',
            ),
            risk_score: $normalized['risk_score'],
            signals: array_map(
                static fn(array $signal): Signal => new Signal($signal['name'], $signal['weight'], $signal['description']),
                $normalized['signals'],
            ),
            detection_flags: DetectionFlags::fromArray($normalized['detection_flags']),
            observed_at: $normalized['observed_at'],
            source: $normalized['source'],
            raw: $raw,
        );
    }
}
