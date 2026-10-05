<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire;

/**
 * A schema-declared field. T is its documented JSON type, not a promise about
 * untrusted JSON. Reading never coerces or discards the original value.
 *
 * @template T
 * @internal
 */
final class Field
{
    public function __construct(public readonly string $name) {}

    /** @param array<mixed> $object */
    public function read(array $object): mixed
    {
        return $object[$this->name] ?? null;
    }
}
