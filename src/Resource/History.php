<?php

declare(strict_types=1);

namespace ShieldLabs\Resource;

use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\Transport;
use ShieldLabs\Internal\Validate;
use ShieldLabs\LookupType;
use ShieldLabs\Model\EntityPage;
use ShieldLabs\Model\HistoryPage;
use ShieldLabs\Model\Identification;

/**
 * History API lookups for one domain, newest first.
 * Available as `$client->history`.
 */
final class History
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;
    public const DEFAULT_PAGE_SIZE = 100;

    private const DOMAIN_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @internal use {@see \ShieldLabs\ShieldLabs::$history}
     */
    public function __construct(private readonly Transport $transport, private readonly ?string $domainId) {}

    /**
     * One page of identifications for an identifier.
     *
     * @param LookupType|string                $type    identifier kind (a {@see LookupType} or its string value)
     * @param string                           $value   UUID for the ID types, dotted IPv4 for ip, a non-empty string without "/" (and not "." or "..") for user_hid
     * @param array{limit?: int, offset?: int, query?: array<string, int|string|array<int, string>>} $options limit 1-100 (default 20). offset other than 0 is refused. query holds flat filters.
     *
     * @throws ValidationException before any request when the arguments are invalid
     * @throws ShieldLabsException
     */
    public function search(LookupType|string $type, string $value, array $options = []): HistoryPage
    {
        Options::assertKnown($options, ['limit', 'offset', 'query'], 'history->search()');
        $lookup = Validate::lookupType($type);
        $value = Validate::lookupValue($lookup, $value);
        $limit = Options::integer($options, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = Options::integer($options, 'offset', 0, 0);
        if ($offset !== 0) {
            throw new ValidationException('offset is not accepted. Continue with page_token.');
        }

        return $this->fetch($lookup, $value, $limit, $this->filters($options));
    }

    /**
     * One page of identifications or user rows for this domain.
     *
     * @param array{limit?: int, query?: array<string, int|string|array<int, string>>} $options
     */
    public function list(string $resource, array $options = []): EntityPage
    {
        Options::assertKnown($options, ['limit', 'query'], 'history->list()');
        if ($resource !== 'identifications' && $resource !== 'user') {
            throw new ValidationException('list supports identifications and user.');
        }
        $limit = Options::integer($options, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $body = $this->transport->getJson($this->domainPrefix() . '/history/' . $resource, ['limit' => $limit] + $this->filters($options));
        if (!\is_array($body)) {
            throw new ApiException('The History API returned an unexpected response body.', 200, $body);
        }

        return EntityPage::fromArray($body);
    }

    /**
     * Domains this key can read. `id` is the only legal domain id.
     *
     * @return list<array{id: string, domain: string}>
     */
    public function domains(): array
    {
        $body = $this->transport->getJson('/api/v1/domains');
        if (!\is_array($body) || !\is_array($body['domains'] ?? null)) {
            throw new ApiException('The History API returned an unexpected domain list.', 200, $body);
        }
        $rows = [];
        foreach ($body['domains'] as $row) {
            if (!\is_array($row) || !\is_string($row['id'] ?? null) || !\is_string($row['domain'] ?? null)) {
                continue;
            }
            $rows[] = ['id' => strtolower($row['id']), 'domain' => $row['domain']];
        }

        return $rows;
    }

    /**
     * Every identification for an identifier, fetched page by page as you iterate.
     * Rows are de-duplicated on identification_id (offset paging can repeat a row while new
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
     * @param array<string, int|string|array<int, string>> $filters
     * @param int|null                                     $maxRetries     retries for this call instead of the client setting
     * @param float|null                                   $attemptTimeout seconds each attempt may take at most, where the HTTP client allows it
     *
     * @throws ShieldLabsException
     */
    public function fetch(LookupType $type, string $value, int $limit, array $filters = [], ?int $maxRetries = null, ?float $attemptTimeout = null): HistoryPage
    {
        $path = $this->domainPrefix() . '/history/' . self::resource($type) . '/' . Validate::pathSegment($value);
        $body = $this->transport->getJson($path, ['limit' => $limit] + $filters, $maxRetries, $attemptTimeout);
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
            $page = $this->fetch($type, $value, $pageSize);
            if ($page->data === []) {
                return;
            }
            foreach ($page->data as $identification) {
                if (isset($seen[$identification->identification_id])) {
                    continue;
                }
                $seen[$identification->identification_id] = true;
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

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, int|string|array<int, string>>
     */
    private function filters(array $options): array
    {
        $query = $options['query'] ?? [];
        if (!\is_array($query)) {
            throw new ValidationException('query must be an array of flat filters.');
        }
        /** @var array<string, int|string|array<int, string>> $query */

        return $query;
    }

    private function domainPrefix(): string
    {
        if (!\is_string($this->domainId) || preg_match(self::DOMAIN_ID, $this->domainId) !== 1) {
            throw new ValidationException('domain_id must be the public domain UUID from domains().');
        }

        return '/api/v1/domains/' . strtolower($this->domainId);
    }

    private static function resource(LookupType $type): string
    {
        return match ($type) {
            LookupType::IdentificationId, LookupType::RequestId => 'identifications',
            LookupType::UserHid => 'user',
            LookupType::VisitorId => 'visitor',
            LookupType::DeviceId => 'device',
            LookupType::Ip => 'ip',
            default => throw new ValidationException('That lookup is not a History resource. Use identifications, user, visitor, device or ip.'),
        };
    }
}
