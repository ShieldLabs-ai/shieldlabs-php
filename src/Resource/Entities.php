<?php

declare(strict_types=1);

namespace ShieldLabs\Resource;

use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\Transport;
use ShieldLabs\Model\EntityPage;

/**
 * Domain-scoped entity History: `GET /api/v1/domains/{uuid}/history/{resource}`.
 * Lists do not send `include_total`. Available as `$client->entities`.
 */
final class Entities
{
    private const RESOURCES = ['identifications', 'user', 'visitor', 'device', 'ip'];

    /**
     * @internal use {@see \ShieldLabs\ShieldLabs::$entities}
     */
    public function __construct(private readonly Transport $transport) {}

    /**
     * One page of identifications or users.
     *
     * @param array{
     *     limit?: int,
     *     page_token?: string,
     *     from?: string,
     *     to?: string,
     *     filter?: array<string, mixed>,
     * } $options
     *
     * @throws ValidationException
     * @throws ShieldLabsException
     */
    public function list(string $domain, string $resource, array $options = []): EntityPage
    {
        Options::assertKnown($options, ['limit', 'page_token', 'from', 'to', 'filter'], 'entities->list');
        if (!\in_array($resource, ['identifications', 'user'], true)) {
            throw new ValidationException('Only identifications and user have lists.');
        }
        $path = self::base($domain, $resource);
        $limit = $options['limit'] ?? 20;
        if (!\is_int($limit) || $limit < 1 || $limit > 100) {
            throw new ValidationException('limit must be from 1 through 100.');
        }
        $query = self::window($options);
        $query['limit'] = $limit;
        if (isset($options['page_token'])) {
            $token = $options['page_token'];
            if (!\is_string($token) || $token === '' || \strlen($token) > 4096) {
                throw new ValidationException('Invalid page_token.');
            }
            $query['page_token'] = $token;
        }
        if (isset($options['filter'])) {
            $query['filter'] = self::filter($options['filter']);
        }
        $body = $this->transport->getJson($path, $query);
        $page = self::page($body);
        $expected = $resource === 'identifications' ? 'identification' : $resource;
        if (\count($page->data) > $limit) {
            throw self::invalid();
        }
        foreach ($page->data as $row) {
            $domainId = $row['domain_id'];
            if (!\is_string($domainId) || strcasecmp($domainId, $domain) !== 0 || $row['type'] !== $expected) {
                throw self::invalid();
            }
        }

        return $page;
    }

    /**
     * One entity card. The response body is `{"data": {...}}`.
     *
     * @param array{from?: string, to?: string} $options
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws ShieldLabsException
     */
    public function get(string $domain, string $resource, string $id, array $options = []): array
    {
        Options::assertKnown($options, ['from', 'to'], 'entities->get');
        $path = self::base($domain, $resource);
        $normalized = self::normalizeId($resource, $id);
        $query = self::window($options);
        $body = $this->transport->getJson($path . '/' . rawurlencode($normalized), $query);
        if (!\is_array($body) || !isset($body['data']) || !\is_array($body['data'])) {
            throw self::invalid();
        }
        $entity = self::entity($body['data']);
        $expected = $resource === 'identifications' ? 'identification' : $resource;
        $domainId = $entity['domain_id'];
        if ($entity['id'] !== $normalized || !\is_string($domainId) || strcasecmp($domainId, $domain) !== 0 || $entity['type'] !== $expected) {
            throw self::invalid();
        }

        return $entity;
    }

    private static function base(string $domain, string $resource): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $domain) !== 1) {
            throw new ValidationException('domain must be the public domain UUID.');
        }
        if (!\in_array($resource, self::RESOURCES, true)) {
            throw new ValidationException('Unknown History resource.');
        }

        return '/api/v1/domains/' . strtolower($domain) . '/history/' . $resource;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, int|string>
     */
    private static function window(array $options): array
    {
        $hasFrom = \array_key_exists('from', $options);
        $hasTo = \array_key_exists('to', $options);
        if ($hasFrom !== $hasTo) {
            throw new ValidationException('Send both from and to, or neither.');
        }
        if (!$hasFrom) {
            return [];
        }
        $from = $options['from'];
        $to = $options['to'];
        if (!\is_string($from) || !\is_string($to) || !self::rfc3339($from) || !self::rfc3339($to) || strtotime($from) >= strtotime($to)) {
            throw new ValidationException('History dates must be RFC3339, and from must be before to.');
        }

        return ['from' => $from, 'to' => $to];
    }

    private static function rfc3339(string $value): bool
    {
        if (preg_match('/^\d{4}-\d\d-\d\dT.*(?:Z|[+-]\d\d:\d\d)$/', $value) !== 1) {
            return false;
        }
        $parsed = strtotime($value);

        return $parsed !== false;
    }

    /**
     * @param mixed $filter
     */
    private static function filter(mixed $filter): string
    {
        if (!\is_array($filter)) {
            throw new ValidationException('Unsupported History filter.');
        }
        foreach (array_keys($filter) as $key) {
            if ($key !== 'identification' && $key !== 'entity') {
                throw new ValidationException('Unsupported History filter.');
            }
        }
        if (isset($filter['identification'])) {
            self::keys($filter['identification'], ['risk_bands', 'hre_min_accounts', 'hre_min_devices'], 'Search does not accept ids, device_id_source or chips.');
        }
        if (isset($filter['entity'])) {
            self::keys($filter['entity'], ['ids', 'risk_bands'], 'Unsupported entity filter.');
        }
        try {
            return json_encode($filter, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new ValidationException('Unsupported History filter.');
        }
    }

    /**
     * @param mixed        $value
     * @param list<string> $allowed
     */
    private static function keys(mixed $value, array $allowed, string $message): void
    {
        if (!\is_array($value)) {
            throw new ValidationException($message);
        }
        foreach (array_keys($value) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new ValidationException($message);
            }
        }
    }

    public static function normalizeId(string $resource, string $id): string
    {
        if ($id === '' || \strlen($id) > 512 || str_contains($id, '/') || str_contains($id, '\\') || $id === '.' || $id === '..' || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new ValidationException('Invalid entity id.');
        }
        if ($resource === 'ip') {
            if (str_contains($id, ':')) {
                $packed = @inet_pton($id);
                if ($packed === false || \strlen($packed) !== 16) {
                    throw new ValidationException('Invalid IP entity id.');
                }
                $mapped = inet_ntop(substr($packed, 12));
                $prefix = substr($packed, 0, 12);
                if ($prefix !== "\0\0\0\0\0\0\0\0\0\0\xff\xff" || !\is_string($mapped) || !filter_var($mapped, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                    throw new ValidationException('Pure IPv6 is not supported by History.');
                }

                return $mapped;
            }
            if (!filter_var($id, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                throw new ValidationException('Invalid IP entity id.');
            }

            return $id;
        }
        if (\in_array($resource, ['identifications', 'visitor', 'device'], true)) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
                throw new ValidationException('This entity id must be a UUID.');
            }

            return strtolower($id);
        }

        return $id;
    }

    /**
     * @param mixed $body
     */
    private static function page(mixed $body): EntityPage
    {
        if (!\is_array($body) || !isset($body['data']) || !\is_array($body['data']) || !\array_key_exists('next_page_token', $body)) {
            throw self::invalid();
        }
        $token = $body['next_page_token'];
        if ($token !== null && !\is_string($token)) {
            throw self::invalid();
        }
        if (\array_key_exists('total', $body) && (!\is_int($body['total']) || $body['total'] < 0)) {
            throw self::invalid();
        }
        $data = [];
        foreach ($body['data'] as $row) {
            $data[] = self::entity(\is_array($row) ? $row : []);
        }

        return new EntityPage($data, $token);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function entity(array $value): array
    {
        $type = $value['type'] ?? null;
        $id = $value['id'] ?? null;
        $domainId = $value['domain_id'] ?? null;
        if (!\in_array($type, ['identification', 'user', 'visitor', 'device', 'ip'], true) || !\is_string($id) || $id === '' || !\is_string($domainId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $domainId) !== 1 || !\is_string($value['domain'] ?? null)) {
            throw self::invalid();
        }
        self::risk(\is_array($value['risk'] ?? null) ? $value['risk'] : [], $type);
        self::signals(\is_array($value['signals'] ?? null) ? $value['signals'] : []);
        if (\array_key_exists('identifications', $value) && $value['identifications'] !== null && (!\is_int($value['identifications']) || $value['identifications'] < 0)) {
            throw self::invalid();
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<mixed> $risk
     */
    private static function risk(array $risk, string $type): void
    {
        if (($risk['state'] ?? null) === 'ok') {
            $asOf = $risk['as_of'] ?? null;
            if (!\in_array($risk['band'] ?? null, ['trusted', 'suspicious', 'dangerous'], true) || !\is_string($asOf) || !self::rfc3339($asOf)) {
                throw self::invalid();
            }
            if (\array_key_exists('score', $risk)) {
                $score = $risk['score'];
                if (!\is_int($score) || $score < 0 || $score > 100) {
                    throw self::invalid();
                }
            } elseif ($type === 'identification') {
                throw self::invalid();
            }

            return;
        }
        if (($risk['state'] ?? null) !== 'unavailable' || ($risk['band'] ?? null) !== null || ($risk['as_of'] ?? null) !== null || \array_key_exists('score', $risk)) {
            throw self::invalid();
        }
    }

    /**
     * @param array<mixed> $signals
     */
    private static function signals(array $signals): void
    {
        $items = $signals['items'] ?? null;
        if (!\in_array($signals['state'] ?? null, ['ok', 'unavailable'], true) || !\is_array($items)) {
            throw self::invalid();
        }
        foreach ($items as $item) {
            if (!\is_string($item)) {
                throw self::invalid();
            }
        }
    }

    private static function invalid(): ApiException
    {
        return new ApiException('The History API returned an invalid entity response.', 200);
    }
}
