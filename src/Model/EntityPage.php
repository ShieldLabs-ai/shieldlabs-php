<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

use ShieldLabs\Exception\ApiException;

/** Current entity cards and an opaque cursor. No global total is returned. */
final class EntityPage
{
    /** @param list<array<mixed>> $data */
    public function __construct(
        public readonly array $data,
        public readonly ?string $next_page_token,
    ) {}

    /** @param array<mixed> $body */
    public static function fromArray(array $body): self
    {
        $rows = $body['data'] ?? null;
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new ApiException('History returned an invalid entity page.', 200, $body);
        }
        $data = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['id'] ?? null) || !\is_string($row['type'] ?? null) || !\is_array($row['risk'] ?? null)) {
                throw new ApiException('History returned an invalid entity card.', 200, $body);
            }
            $data[] = $row;
        }
        $token = $body['next_page_token'] ?? null;
        if ($token !== null && !\is_string($token)) {
            throw new ApiException('History returned an invalid page token.', 200, $body);
        }

        return new self($data, $token);
    }
}
