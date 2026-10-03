<?php

declare(strict_types=1);

namespace ShieldLabs\Internal\Wire;

/**
 * Statically check the type a normalizer expects without rejecting legacy or
 * malformed input. The existing normalizers still own coercion and defaults.
 *
 * @internal
 */
final class Read
{
    /**
     * @param Field<string>|Field<string|null> $field
     * @param array<mixed> $object
     */
    public static function text(Field $field, array $object): mixed
    {
        return $field->read($object);
    }

    /**
     * @param Field<int> $field
     * @param array<mixed> $object
     */
    public static function integer(Field $field, array $object): mixed
    {
        return $field->read($object);
    }

    /**
     * @param Field<bool> $field
     * @param array<mixed> $object
     */
    public static function boolean(Field $field, array $object): mixed
    {
        return $field->read($object);
    }

    /**
     * @param Field<array<mixed>> $field
     * @param array<mixed> $object
     */
    public static function collection(Field $field, array $object): mixed
    {
        return $field->read($object);
    }

    /**
     * @param Field<int> $field
     * @return array<string, int>
     */
    public static function integerParameter(Field $field, int $value): array
    {
        return [$field->name => $value];
    }

    /**
     * @param Field<string> $field
     * @return array<string, string>
     */
    public static function textParameter(Field $field, string $value): array
    {
        return [$field->name => $value];
    }
}
