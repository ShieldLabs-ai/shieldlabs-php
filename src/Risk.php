<?php

declare(strict_types=1);

namespace ShieldLabs;

use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\Time;
use ShieldLabs\Model\DetectionFlags;
use ShieldLabs\Model\Identification;

/**
 * Risk helpers: bands, the rate-limit marker and a reusable policy check.
 */
final class Risk
{
    /** Default freshness window of {@see Risk::evaluate()}, in seconds. */
    public const DEFAULT_MAX_AGE = 300;

    /** Flags that refuse an identification by default in {@see Risk::evaluate()}. */
    public const DEFAULT_BLOCK_FLAGS = ['browser_automation', 'javascript_disabled'];

    private function __construct() {}

    /**
     * Band of a Risk Score: trusted 0-29, suspicious 30-59, dangerous 60-100, and
     * {@see RiskBand::RateLimited} for the rate-limit marker (above 100).
     */
    public static function band(int $score): RiskBand
    {
        return RiskBand::fromScore($score);
    }

    /**
     * True when the value is the rate-limit marker (above 100), never a real score.
     */
    public static function isRateLimited(int $score): bool
    {
        return $score > 100;
    }

    /**
     * Guard for one protected action (signup, login, checkout). The checks run in
     * this order and the first failure wins:
     *
     * 1. no identification: `missing` (unverified, never "clean")
     * 2. `is_replay($requestId)` returns true: `replayed` (the SDK stores nothing itself)
     * 3. older than `max_age` seconds by `observed_at`: `stale`
     * 4. score above 100 (rate-limit marker): `rate_limited`
     * 5. all-zero device ID: `no_device_signals`
     * 6. any flag in `block_flags`: `blocked_flag`
     * 7. band in `block_bands`: `blocked_band`
     *
     * The defaults (max_age 300, block_bands [dangerous], block_flags
     * [browser_automation, javascript_disabled]) are a starting point to tune for your
     * product.
     *
     * @param array{
     *     max_age?: int|float,
     *     now?: \DateTimeInterface|int|float,
     *     block_bands?: list<RiskBand|string>,
     *     block_flags?: list<string>,
     *     is_replay?: callable(string): bool,
     * } $options
     *
     * @throws ValidationException when an option is invalid
     */
    public static function evaluate(?Identification $identification, array $options = []): Evaluation
    {
        Options::assertKnown($options, ['max_age', 'now', 'block_bands', 'block_flags', 'is_replay'], 'Risk::evaluate()');
        $maxAge = Options::seconds($options, 'max_age', (float) self::DEFAULT_MAX_AGE, true);
        $now = self::now($options['now'] ?? null);
        $blockBands = self::blockBands($options['block_bands'] ?? [RiskBand::Dangerous]);
        $blockFlags = self::blockFlags($options['block_flags'] ?? self::DEFAULT_BLOCK_FLAGS);
        $isReplay = $options['is_replay'] ?? null;
        if ($isReplay !== null && !\is_callable($isReplay)) {
            throw new ValidationException('Option "is_replay" must be a callable that receives the request ID.');
        }

        if ($identification === null) {
            return new Evaluation(false, EvaluationReason::Missing, null);
        }
        $band = $identification->riskBand();
        if ($isReplay !== null && (bool) $isReplay($identification->request_id)) {
            return new Evaluation(false, EvaluationReason::Replayed, $band);
        }
        $observedAt = $identification->observed_at;
        if ($observedAt === null || $now - Time::unix($observedAt) > $maxAge) {
            return new Evaluation(false, EvaluationReason::Stale, $band);
        }
        if ($identification->isRateLimited()) {
            return new Evaluation(false, EvaluationReason::RateLimited, $band);
        }
        if (!$identification->hasDeviceSignals()) {
            return new Evaluation(false, EvaluationReason::NoDeviceSignals, $band);
        }
        foreach ($blockFlags as $flag) {
            if ($identification->detection_flags->get($flag)) {
                return new Evaluation(false, EvaluationReason::BlockedFlag, $band, $flag);
            }
        }
        if (\in_array($band, $blockBands, true)) {
            return new Evaluation(false, EvaluationReason::BlockedBand, $band);
        }

        return new Evaluation(true, null, $band);
    }

    /**
     * @throws ValidationException
     */
    private static function now(mixed $now): float
    {
        if ($now === null) {
            return microtime(true);
        }
        if ($now instanceof \DateTimeInterface) {
            return Time::unix($now);
        }
        if ((\is_int($now) || \is_float($now)) && is_finite((float) $now)) {
            return (float) $now;
        }

        throw new ValidationException('Option "now" must be a DateTimeInterface or a Unix timestamp in seconds.');
    }

    /**
     * @return list<RiskBand>
     *
     * @throws ValidationException
     */
    private static function blockBands(mixed $bands): array
    {
        if (!\is_array($bands)) {
            throw new ValidationException('Option "block_bands" must be a list of risk bands.');
        }
        $result = [];
        foreach ($bands as $band) {
            $value = $band instanceof RiskBand ? $band : (\is_string($band) ? RiskBand::tryFrom($band) : null);
            if ($value === null) {
                throw new ValidationException(\sprintf(
                    'Option "block_bands" accepts RiskBand cases or %s.',
                    implode(', ', array_map(static fn(RiskBand $case): string => '"' . $case->value . '"', RiskBand::cases())),
                ));
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function blockFlags(mixed $flags): array
    {
        if (!\is_array($flags)) {
            throw new ValidationException('Option "block_flags" must be a list of detection flag names.');
        }
        $result = [];
        foreach ($flags as $flag) {
            if (!\is_string($flag) || !\in_array($flag, DetectionFlags::KEYS, true)) {
                throw new ValidationException(\sprintf(
                    'Option "block_flags" accepts detection flag names: %s.',
                    implode(', ', DetectionFlags::KEYS),
                ));
            }
            $result[] = $flag;
        }

        return $result;
    }
}
