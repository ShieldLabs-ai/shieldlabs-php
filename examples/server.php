<?php

declare(strict_types=1);

/*
 * ShieldLabs example: a signup guard and a webhook receiver on PHP's built-in server.
 *
 * From the repository root:
 *
 *   composer install
 *   export SHIELDLABS_API_KEY=sec_your_private_key
 *   export SHIELDLABS_WEBHOOK_SECRET=whsec_your_signing_secret
 *   php -S localhost:8000 examples/server.php
 *
 * Routes:
 *   POST /signup               body {"requestId": "..."} (JSON) or form field requestId
 *   POST /webhooks/shieldlabs  signed identification.scored and webhook.ping deliveries
 *
 * SHIELDLABS_WEBHOOK_SECRET may hold several comma-separated secrets while you
 * rotate an endpoint secret. SHIELDLABS_API_BASE_URL overrides the History API
 * origin (for example a local mock).
 */

use ShieldLabs\Event\IdentificationScoredEvent;
use ShieldLabs\Event\WebhookPingEvent;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\SignatureVerificationException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Exception\WebhookParseException;
use ShieldLabs\Risk;
use ShieldLabs\ShieldLabs;
use ShieldLabs\Webhook;

// In your own project, require your Composer autoloader instead.
require __DIR__ . '/../vendor/autoload.php';

/**
 * Remembers keys across requests in a JSON file (the built-in server keeps nothing in
 * memory between requests). Use your database or cache in production, with a unique
 * constraint on the key.
 */
final class UsedKeys
{
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly string $file) {}

    /**
     * Records the key. Returns false when it was already recorded.
     */
    public function add(string $key): bool
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open ' . $this->file);
        }
        try {
            flock($handle, LOCK_EX);
            $decoded = json_decode((string) stream_get_contents($handle), true);
            $keys = [];
            foreach (is_array($decoded) ? $decoded : [] as $name => $time) {
                if (is_int($time) && $time > time() - self::RETENTION_SECONDS) {
                    $keys[(string) $name] = $time;
                }
            }
            if (isset($keys[$key])) {
                return false;
            }
            $keys[$key] = time();
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($keys));

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

/**
 * @param array<string, mixed> $body
 */
function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body), "\n";
}

function requestIdFromBody(): ?string
{
    if (isset($_POST['requestId']) && is_string($_POST['requestId'])) {
        return $_POST['requestId'];
    }
    $json = json_decode((string) file_get_contents('php://input'), true);
    $requestId = is_array($json) ? ($json['requestId'] ?? null) : null;

    return is_string($requestId) && $requestId !== '' ? $requestId : null;
}

/**
 * The History API client, created once per process and reused: it keeps no
 * per-request state (in a framework, register it as a shared service).
 *
 * @throws ShieldLabsException when SHIELDLABS_API_KEY is missing
 */
function shieldlabs(): ShieldLabs
{
    /** @var ShieldLabs|null $client */
    static $client = null;

    return $client ??= new ShieldLabs(); // reads SHIELDLABS_API_KEY (and SHIELDLABS_API_BASE_URL)
}

function handleSignup(UsedKeys $usedKeys): void
{
    $requestId = requestIdFromBody();
    if ($requestId === null) {
        respond(400, ['ok' => false, 'error' => 'requestId is required']);

        return;
    }

    try {
        $client = shieldlabs();
    } catch (ShieldLabsException $exception) {
        error_log('ShieldLabs client setup failed: ' . $exception->getMessage());
        respond(500, ['ok' => false, 'error' => 'server misconfigured']);

        return;
    }

    try {
        // Waits up to 10 s for the verdict: the History row appears about 1-3 s after the browser call.
        $identification = $client->identifications->get($requestId);
    } catch (ValidationException) {
        respond(400, ['ok' => false, 'error' => 'requestId must be a UUID']);

        return;
    } catch (ShieldLabsException $exception) {
        error_log('ShieldLabs lookup failed: ' . $exception->getMessage());
        // Unverified is never "clean": refuse, or send the signup to review.
        respond(503, ['ok' => false, 'reason' => 'verification_unavailable']);

        return;
    }

    // Missing, reused, older than 5 minutes, rate-limit marker, no device signals,
    // browser automation or JavaScript disabled, or the dangerous band: refuse.
    $evaluation = Risk::evaluate($identification, [
        'is_replay' => static fn(string $id): bool => !$usedKeys->add('request:' . $id),
    ]);
    if (!$evaluation->ok) {
        respond(403, ['ok' => false] + $evaluation->toArray());

        return;
    }

    // Create the account here.
    respond(200, ['ok' => true, 'band' => $evaluation->band?->value]);
}

function handleWebhook(UsedKeys $usedKeys): void
{
    $payload = (string) file_get_contents('php://input');
    $secrets = array_values(array_filter(array_map('trim', explode(',', (string) getenv('SHIELDLABS_WEBHOOK_SECRET')))));
    $header = $_SERVER['HTTP_X_SHIELD_SIGNATURE'] ?? null;

    try {
        $event = Webhook::constructEvent($payload, is_string($header) ? $header : null, $secrets);
    } catch (SignatureVerificationException $exception) {
        error_log('Rejected webhook: ' . $exception->getMessage());
        respond(401, ['error' => 'invalid signature']);

        return;
    } catch (WebhookParseException) {
        respond(400, ['error' => 'invalid payload']);

        return;
    }

    if ($event instanceof IdentificationScoredEvent) {
        $identification = $event->data;
        // One delivery per identification today, without retries. Future retries resend
        // identical bytes, so stay idempotent on request_id.
        if ($usedKeys->add('webhook:' . $identification->request_id)) {
            error_log(sprintf(
                'identification.scored request_id=%s risk_score=%d band=%s flags=%s',
                $identification->request_id,
                $identification->risk_score,
                $identification->riskBand()->value,
                implode(',', $identification->detection_flags->active()) ?: 'none',
            ));
        } else {
            error_log('Duplicate delivery ignored for request_id=' . $identification->request_id);
        }
    } elseif ($event instanceof WebhookPingEvent) {
        error_log('webhook.ping received');
    } else {
        error_log('Unhandled event type ' . $event->event_type);
    }

    // Answer fast (well under 1 s); do slow work after responding or in a queue.
    respond(200, ['received' => true]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$usedKeys = new UsedKeys(sys_get_temp_dir() . '/shieldlabs-example-keys.json');

if ($method === 'POST' && $path === '/signup') {
    handleSignup($usedKeys);
} elseif ($method === 'POST' && $path === '/webhooks/shieldlabs') {
    handleWebhook($usedKeys);
} else {
    respond(404, ['error' => 'not found']);
}
