<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

/**
 * Where the visit came from. Every value is "" when absent.
 */
final class TrafficSource implements \JsonSerializable
{
    /**
     * @param string $channel         for example "Google Ads", "Organic Search", "Referral", "Direct"
     * @param string $referrer_domain registrable domain of the referrer, without "www."
     * @param string $landing_url     landing page URL without the fragment (may carry query-string data)
     * @param string $click_id_type   gclid, gbraid, wbraid, msclkid, ttclid or fbclid
     */
    public function __construct(
        public readonly string $channel = '',
        public readonly string $referrer_domain = '',
        public readonly string $landing_url = '',
        public readonly string $click_id_type = '',
        public readonly string $utm_source = '',
        public readonly string $utm_medium = '',
        public readonly string $utm_campaign = '',
        public readonly string $utm_content = '',
        public readonly string $utm_term = '',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'referrer_domain' => $this->referrer_domain,
            'landing_url' => $this->landing_url,
            'click_id_type' => $this->click_id_type,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'utm_content' => $this->utm_content,
            'utm_term' => $this->utm_term,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
