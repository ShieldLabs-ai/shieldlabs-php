<?php

declare(strict_types=1);

namespace ShieldLabs\Resource;

use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\Transport;
use ShieldLabs\Internal\Validate;
use ShieldLabs\Internal\Wire\Generated\HistoryParameters;
use ShieldLabs\Internal\Wire\Read;
use ShieldLabs\LookupType;
use ShieldLabs\Model\HistoryPage;
use ShieldLabs\Model\Identification;

/**
 * History API lookups: `GET /api/v1/history/{type}/{value}`, newest first.
 * Available as `$client->history`.
 */
final class History
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;
    public const DEFAULT_PAGE_SIZE = 100;

    /**
     * @internal use {@see \ShieldLabs\ShieldLabs::$history}
     */
    public function __construct(private readonly Transport $transport) {}

    /**
     * One page of identifications for an identifier.
     *
     * @param LookupType|string                $type    identifier kind (a {@see LookupType} or its string value)
     * @param string                           $value   UUID for the ID types, dotted IPv4 for ip, a non-empty string without "/" (and not "." or "..") for user_hid
     * @param array{limit?: int, offset?: int} $options limit 1-100 (default 20), offset 0 or more (default 0)
     *
     * @throws ValidationException before any request when the arguments are invalid
     * @throws ShieldLabsException
     */
    public function search(LookupType|string $type, string $value, array $options = []): HistoryPage
    {
        Options::assertKnown($options, ['limit', 'offset'], 'history->search()');
        $lookup = Validate::lookupType($type);
        $value = Validate::lookupValue($lookup, $value);
        $limit = Options::integer($options, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = Options::integer($options, 'offset', 0, 0);

        return $this->fetch($lookup, $value, $limit, $offset);
    }

    /**
     * Every identification for an identifier, fetched page by page as you iterate.
     * Rows are de-duplicated on request_id (offset paging can repeat a row while new
     * rows arrive); iteration stops at the reported total, at an empty page or after
     * max_items rows. With max_items 0 it yields nothing and sends no request.
     *
     * @param LookupType|string                          $type
     * @param array{page_size?: int, max_items?: int|null} $options page_size 1-100 (default 100), max_items 0 or more to stop early (default: no limit)
     *
     * @return \Generator<int, Identification, mixed, void>
     *
     * @throws ValidationException immediately when the arguments are invalid
     */
    public function iterate(LookupType|string $type, string $value, array $options = []): \Generator
    {
        Options::assertKnown($options, ['page_size', 'max_items'], 'history->iterate()');
        $lookup = Validate::lookupType($type);
        $value = Validate::lookupValue($lookup, $value);
        $pageSize = Options::integer($options, 'page_size', self::DEFAULT_PAGE_SIZE, 1, self::MAX_LIMIT);
        $maxItems = ($options['max_items'] ?? null) === null ? null : Options::integer($options, 'max_items', 0, 0);

        return $this->pages($lookup, $value, $pageSize, $maxItems);
    }

    /**
     * @internal inputs must already be validated
     *
     * @param int|null   $maxRetries     retries for this call instead of the client setting
     * @param float|null $attemptTimeout seconds each attempt may take at most, where the HTTP client allows it
     *
     * @throws ShieldLabsException
     */
    public function fetch(LookupType $type, string $value, int $limit, int $offset, ?int $maxRetries = null, ?float $attemptTimeout = null): HistoryPage
    {
        $path = HistoryParameters::path(search_type: $type->value, value: Validate::pathSegment($value));
        $query = Read::integerParameter(HistoryParameters::limit(), $limit)
            + Read::integerParameter(HistoryParameters::offset(), $offset);
        $body = $this->transport->getJson($path, $query, $maxRetries, $attemptTimeout);
        if (!\is_array($body)) {
            throw new ApiException('The History API returned an unexpected response body.', 200, $body);
        }

        return HistoryPage::fromArray($body);
    }

    /**
     * @return \Generator<int, Identification, mixed, void>
     */
    private function pages(LookupType $type, string $value, int $pageSize, ?int $maxItems): \Generator
    {
        if ($maxItems === 0) {
            return;
        }
        $seen = [];
        $yielded = 0;
        $offset = 0;
        while (true) {
            $page = $this->fetch($type, $value, $pageSize, $offset);
            if ($page->data === []) {
                return;
            }
            foreach ($page->data as $identification) {
                if (isset($seen[$identification->request_id])) {
                    continue;
                }
                $seen[$identification->request_id] = true;
                yield $identification;
                ++$yielded;
                if ($maxItems !== null && $yielded >= $maxItems) {
                    return;
                }
            }
            $offset += \count($page->data);
            if ($offset >= $page->total) {
                return;
            }
        }
    }
}
