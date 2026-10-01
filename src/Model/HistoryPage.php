<?php

declare(strict_types=1);

namespace ShieldLabs\Model;

/**
 * One page of History API results, newest first.
 *
 * @implements \IteratorAggregate<int, Identification>
 */
final class HistoryPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Identification> $data  identifications on this page
     * @param int                  $total number of rows that match the lookup (all pages)
     */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
    ) {}

    /**
     * Builds a page from a decoded History API response body.
     *
     * @param array<mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $rows = $body['data'] ?? null;
        $data = [];
        if (\is_array($rows)) {
            foreach ($rows as $row) {
                if (\is_array($row)) {
                    $data[] = Identification::fromHistoryRow($row);
                }
            }
        }
        $total = $body['total'] ?? null;
        if (\is_float($total) && is_finite($total)) {
            $total = (int) $total;
        }

        return new self($data, \is_int($total) ? $total : \count($data));
    }

    /**
     * @return \ArrayIterator<int, Identification>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * Number of identifications on this page (not the total).
     */
    public function count(): int
    {
        return \count($this->data);
    }
}
