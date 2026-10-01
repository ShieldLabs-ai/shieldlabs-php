<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

use ShieldLabs\Internal\Normalizer;
use ShieldLabs\Internal\Time;

/**
 * Domain profile from the Management API.
 */
final class DomainProfile implements \JsonSerializable
{
    /**
     * @param string                  $domain                    the registered domain
     * @param int                     $remaining_identifications identifications left in the account's included volume; negative when the account is over it
     * @param string                  $public_key_masked         Public Key with every character except the last 4 replaced by "*"
     * @param string                  $secret_key_masked         Secret Key masked the same way
     * @param \DateTimeImmutable|null $created_at                when the domain was created (UTC)
     * @param array<mixed>            $raw                       the response body as received
     */
    public function __construct(
        public readonly string $domain,
        public readonly int $remaining_identifications,
        public readonly string $public_key_masked,
        public readonly string $secret_key_masked,
        public readonly ?\DateTimeImmutable $created_at,
        public readonly array $raw = [],
    ) {}

    /**
     * Builds a profile from a decoded Management API response body.
     *
     * @param array<mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $string = static fn(string $key): string => \is_string($body[$key] ?? null) ? $body[$key] : '';
        $weight = $body['Weight'] ?? null;
        if (\is_float($weight) && is_finite($weight)) {
            $weight = (int) $weight;
        }

        return new self(
            domain: $string('Domain'),
            remaining_identifications: \is_int($weight) ? $weight : 0,
            public_key_masked: $string('PublicKey'),
            secret_key_masked: $string('Secret'),
            created_at: Normalizer::parseRfc3339($body['CreatedAt'] ?? null),
            raw: $body,
        );
    }

    /**
     * The normalized profile as plain values (without `raw`).
     *
     * @return array{domain: string, remaining_identifications: int, public_key_masked: string, secret_key_masked: string, created_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'remaining_identifications' => $this->remaining_identifications,
            'public_key_masked' => $this->public_key_masked,
            'secret_key_masked' => $this->secret_key_masked,
            'created_at' => Time::format($this->created_at),
        ];
    }

    /**
     * @return array{domain: string, remaining_identifications: int, public_key_masked: string, secret_key_masked: string, created_at: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
