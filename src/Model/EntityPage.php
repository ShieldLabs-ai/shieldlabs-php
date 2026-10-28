<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

/**
 * One domain-scoped entity History page. There is no total.
 */
final class EntityPage
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextPageToken,
    ) {}
}
