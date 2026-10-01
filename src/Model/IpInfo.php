<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

/**
 * An IP address with its country. Both are "" when unknown.
 */
final class IpInfo implements \JsonSerializable
{
    /**
     * @param string $ip      dotted IPv4 address, or "" when there is none
     * @param string $country English country name (for example "Germany"), or ""
     */
    public function __construct(
        public readonly string $ip = '',
        public readonly string $country = '',
    ) {}

    /**
     * @return array{ip: string, country: string}
     */
    public function toArray(): array
    {
        return ['ip' => $this->ip, 'country' => $this->country];
    }

    /**
     * @return array{ip: string, country: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
