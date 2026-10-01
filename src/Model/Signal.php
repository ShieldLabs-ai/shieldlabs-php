<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

/**
 * One weighted risk signal behind a Risk Score.
 *
 * Weights can be negative (corrections) and names can repeat. Never add the weights
 * up yourself: branch on the Risk Score and the detection flags instead.
 */
final class Signal implements \JsonSerializable
{
    /**
     * @param string      $name        signal name, see {@see \ShieldLabs\SignalName} for the known ones
     * @param int         $weight      contribution to the score (999 for the rate-limit marker)
     * @param string|null $description server description (History API rows only, null for webhooks)
     */
    public function __construct(
        public readonly string $name,
        public readonly int $weight,
        public readonly ?string $description = null,
    ) {}

    /**
     * @return array{name: string, weight: int, description: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'weight' => $this->weight, 'description' => $this->description];
    }

    /**
     * @return array{name: string, weight: int, description: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
