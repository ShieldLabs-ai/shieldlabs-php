<?php

declare(strict_types=1);

namespace ShieldLabs;

/**
 * Result of {@see Risk::evaluate()}.
 */
final class Evaluation implements \JsonSerializable
{
    /**
     * @param bool                  $ok     true when no check refused the identification
     * @param EvaluationReason|null $reason why it was refused, null when $ok is true
     * @param RiskBand|null         $band   band of the Risk Score, null when the identification is missing
     * @param string|null           $flag   the detection flag that refused it (reason blocked_flag)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?EvaluationReason $reason,
        public readonly ?RiskBand $band,
        public readonly ?string $flag = null,
    ) {}

    /**
     * @return array{ok: bool, reason: string|null, band: string|null, flag: string|null}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'reason' => $this->reason?->value,
            'band' => $this->band?->value,
            'flag' => $this->flag,
        ];
    }

    /**
     * @return array{ok: bool, reason: string|null, band: string|null, flag: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
