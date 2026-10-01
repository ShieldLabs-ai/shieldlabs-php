<?php

declare(strict_types=1);

namespace ShieldLabs;

use ShieldLabs\Event\IdentificationScoredEvent;
use ShieldLabs\Event\UnknownWebhookEvent;
use ShieldLabs\Event\WebhookPingEvent;
use ShieldLabs\Exception\SignatureVerificationException;
use ShieldLabs\Exception\WebhookParseException;
use ShieldLabs\Internal\Normalizer;
use ShieldLabs\Model\Identification;

/**
 * Webhook signature verification and typed events.
 *
 * Every delivery is a POST with the header
 * `X-Shield-Signature: sha256=<hex HMAC-SHA256 of the raw body>`, keyed with the
 * endpoint signing secret including its `whsec_` prefix. Always verify the raw
 * request body (for example `file_get_contents('php://input')`), never JSON you
 * decoded and encoded again.
 *
 * Today each identification is delivered once per endpoint, with a 1-second timeout
 * and no retries: answer with a 2xx quickly. Future retries resend identical bytes,
 * so make handlers idempotent on `data.request_id`, and use the History API for
 * guaranteed reads.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Shield-Signature';
    public const SCHEMA_VERSION = '2026-06-01';

    private const SIGNATURE_PREFIX = 'sha256=';

    private function __construct() {}

    /**
     * Checks the signature header against the raw body.
     *
     * Returns false for a missing or malformed header, an empty secret or a digest
     * that does not match. Pass several secrets while you rotate an endpoint secret:
     * the delivery is valid when any of them matches.
     *
     * @param string               $payload the raw request body, byte for byte
     * @param string|null          $header  value of the X-Shield-Signature header
     * @param string|array<string> $secret  signing secret (`whsec_...`) or a list of secrets
     */
    public static function verifySignature(string $payload, ?string $header, string|array $secret): bool
    {
        $digest = self::digest($header);
        if ($digest === null) {
            return false;
        }
        $valid = false;
        foreach (self::secrets($secret) as $candidate) {
            if (hash_equals(hash_hmac('sha256', $payload, $candidate), $digest)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * Verifies the delivery and parses it into a typed event.
     *
     * Unknown event types become {@see UnknownWebhookEvent} (never an error), and
     * unknown schema versions are accepted.
     *
     * @param string               $payload the raw request body, byte for byte
     * @param string|null          $header  value of the X-Shield-Signature header
     * @param string|array<string> $secret  signing secret (`whsec_...`) or a list of secrets
     *
     * @throws SignatureVerificationException when the signature is missing, malformed or wrong, or no secret is set
     * @throws WebhookParseException          when a verified body is not a valid event
     */
    public static function constructEvent(
        string $payload,
        ?string $header,
        string|array $secret,
    ): IdentificationScoredEvent|WebhookPingEvent|UnknownWebhookEvent {
        if (self::secrets($secret) === []) {
            throw new SignatureVerificationException('No webhook signing secret is configured (expected the whsec_ value of the endpoint).');
        }
        if ($header === null || trim($header) === '') {
            throw new SignatureVerificationException('The X-Shield-Signature header is missing.');
        }
        if (self::digest($header) === null) {
            throw new SignatureVerificationException('The X-Shield-Signature header is malformed (expected sha256= followed by 64 hex characters).');
        }
        if (!self::verifySignature($payload, $header, $secret)) {
            throw new SignatureVerificationException('The X-Shield-Signature header does not match the request body.');
        }

        return self::parse($payload);
    }

    /**
     * @throws WebhookParseException
     */
    private static function parse(string $payload): IdentificationScoredEvent|WebhookPingEvent|UnknownWebhookEvent
    {
        try {
            $body = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookParseException('The webhook body is not valid JSON.', 0, $exception);
        }
        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new WebhookParseException('The webhook body is not a JSON object.');
        }
        $eventType = $body['event_type'] ?? null;
        if (!\is_string($eventType) || $eventType === '') {
            throw new WebhookParseException('The webhook body has no event_type.');
        }
        $schemaVersion = \is_string($body['schema_version'] ?? null) ? $body['schema_version'] : '';
        $createdAt = Normalizer::parseRfc3339($body['created_at'] ?? null);

        switch ($eventType) {
            case IdentificationScoredEvent::TYPE:
                $data = $body['data'] ?? null;
                if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
                    throw new WebhookParseException('The identification.scored event has no data object.');
                }

                return new IdentificationScoredEvent($eventType, $schemaVersion, $createdAt, Identification::fromWebhookData($data), $body);
            case WebhookPingEvent::TYPE:
                return new WebhookPingEvent($eventType, $schemaVersion, $createdAt, $body);
            default:
                return new UnknownWebhookEvent($eventType, $schemaVersion, $createdAt, $body);
        }
    }

    /**
     * Lowercase hex digest from the header, or null when the header is malformed.
     */
    private static function digest(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        $header = trim($header);
        if (!str_starts_with($header, self::SIGNATURE_PREFIX)) {
            return null;
        }
        $digest = substr($header, \strlen(self::SIGNATURE_PREFIX));
        if (preg_match('/^[0-9a-fA-F]{64}$/D', $digest) !== 1) {
            return null;
        }

        return strtolower($digest);
    }

    /**
     * @param string|array<mixed> $secret
     *
     * @return list<string> the non-empty secrets
     */
    private static function secrets(string|array $secret): array
    {
        $secrets = [];
        foreach (\is_string($secret) ? [$secret] : $secret as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                $secrets[] = $candidate;
            }
        }

        return $secrets;
    }
}
