<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

/** Scoped server attribution. Provider proof does not verify a claimed agent. */
final class ClientIdentity implements \JsonSerializable
{
    /**
     * @param list<array<mixed>> $claims
     * @param list<array<mixed>> $verified Each subject/value has its own evidence_ids.
     * @param list<array<mixed>> $assessments
     * @param list<array<mixed>> $evidence
     * @param array<mixed> $raw Original object, including future fields.
     */
    private function __construct(
        public readonly string $schema_version,
        public readonly int $classification_revision,
        public readonly string $availability,
        public readonly string $registry_revision,
        public readonly string $observed_at,
        public readonly array $claims,
        public readonly array $verified,
        public readonly array $assessments,
        public readonly array $evidence,
        public readonly array $raw,
    ) {}

    public static function fromValue(mixed $value): ?self
    {
        if (!\is_array($value)) {
            return null;
        }
        foreach (['schema_version', 'availability', 'registry_revision', 'observed_at'] as $key) {
            if (!isset($value[$key]) || !\is_string($value[$key])) {
                return null;
            }
        }
        if (!isset($value['classification_revision']) || !\is_int($value['classification_revision']) || $value['classification_revision'] < 1) {
            return null;
        }
        foreach (['claims', 'verified', 'assessments', 'evidence'] as $key) {
            if (!isset($value[$key]) || !\is_array($value[$key]) || !array_is_list($value[$key])) {
                return null;
            }
            foreach ($value[$key] as $entry) {
                if (!\is_array($entry)) {
                    return null;
                }
            }
        }
        /** @var list<array<mixed>> $claims */
        $claims = $value['claims'];
        /** @var list<array<mixed>> $verified */
        $verified = $value['verified'];
        /** @var list<array<mixed>> $assessments */
        $assessments = $value['assessments'];
        /** @var list<array<mixed>> $evidence */
        $evidence = $value['evidence'];
        return new self($value['schema_version'], $value['classification_revision'], $value['availability'], $value['registry_revision'], $value['observed_at'], $claims, $verified, $assessments, $evidence, $value);
    }

    /** @return array<mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
