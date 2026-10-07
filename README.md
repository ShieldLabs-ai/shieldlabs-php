# ShieldLabs PHP SDK

Read ShieldLabs identification verdicts on your PHP backend, verify webhooks and turn Risk Scores into decisions.

[![CI](https://github.com/ShieldLabs-ai/shieldlabs-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ShieldLabs-ai/shieldlabs-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist version](https://img.shields.io/packagist/v/shieldlabs/shieldlabs-php.svg)](https://packagist.org/packages/shieldlabs/shieldlabs-php)

ShieldLabs identifies visitors with device intelligence and scores every identification from 300+ risk signals. New to ShieldLabs? [Start free](https://app.shieldlabs.ai) and read the [documentation](https://docs.shieldlabs.ai).

## How it fits

1. **Browser.** The ShieldLabs agent (loaded with `@shieldlabs-ai/js` or one of the framework packages) runs an identification and hands your page a `requestId`. The browser never sees a Risk Score or a device ID.
2. **Your backend (this SDK).** The page sends the `requestId` with the protected action (signup, login, checkout). Your backend reads the verdict for it from the History API, or receives it in a signed `identification.scored` webhook.
3. **Decision.** Your backend acts on the Risk Score, the risk band, the detection flags and the identifiers (for example: how many accounts one device ID has used).

```
browser --requestId--> your PHP backend --History API--> Identification --> allow / review / refuse
```

## Install

```bash
composer require shieldlabs/shieldlabs-php
```

Requirements: PHP 8.1 or newer, and `ext-curl` unless your project already has Guzzle 7 or Symfony HttpClient (see [HTTP client and timeouts](#http-client-and-timeouts)).

| Dependency | Why it is there |
|---|---|
| `psr/http-client`, `psr/http-factory`, `psr/http-message` | The standard HTTP interfaces, so any PSR-18 client can carry the requests |
| `php-http/discovery` | Finds a PSR-18 client and PSR-17 factories that your project already has. It ships an optional Composer plugin: Composer may ask whether to allow it, and the SDK works either way |
| `nyholm/psr7` | A small PSR-7 and PSR-17 implementation with no dependencies of its own, so the built-in cURL client works in a project without an HTTP stack |

Keys come from the ShieldLabs analytics dashboard at [app.shieldlabs.ai](https://app.shieldlabs.ai). Keep all three on the server (the browser only ever uses the Public Key):

| Key | Used by | Environment variable |
|---|---|---|
| Private API Key (`sec_...`), one per domain | `ShieldLabs` (History API) | `SHIELDLABS_API_KEY` |
| Secret Key and the registered domain | `ShieldLabsManagement` (Management API) | `SHIELDLABS_SECRET_KEY`, `SHIELDLABS_DOMAIN` |
| Webhook signing secret (`whsec_...`), one per endpoint | `Webhook::constructEvent()` | `SHIELDLABS_WEBHOOK_SECRET` |

## Quick start

Save this as `signup.php` next to your `vendor/` folder. It reads the verdict for the `requestId` your frontend sent with the form, then decides:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Risk;
use ShieldLabs\ShieldLabs;

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

// One identification authorizes one action, so remember the request IDs you accepted.
// Marker files keep this snippet self-contained; see "Storing used request IDs" below
// for a database version.
function markRequestIdUsed(string $requestId): bool
{
    $marker = sys_get_temp_dir() . '/shieldlabs-used-' . hash('sha256', $requestId);

    return @fopen($marker, 'x') !== false; // mode "x" fails when the file already exists
}

// The requestId arrives as a form field or in a JSON body.
$json = json_decode((string) file_get_contents('php://input'), true);
$requestId = $_POST['requestId'] ?? (is_array($json) ? ($json['requestId'] ?? null) : null);

$shieldlabs = new ShieldLabs(['api_key' => 'sec_your_private_key']); // or new ShieldLabs() to read SHIELDLABS_API_KEY

try {
    // Polls the History API until the verdict is stored (usually 1-3 seconds after
    // the browser call), for up to 10 seconds by default.
    $identification = $shieldlabs->identifications->get(is_string($requestId) ? $requestId : '');
} catch (ValidationException) {
    respond(400, ['error' => 'requestId must be a UUID']);
} catch (ShieldLabsException) {
    respond(503, ['error' => 'verification unavailable']); // unverified is never "clean"
}

$evaluation = Risk::evaluate($identification, [
    'is_replay' => fn(string $id): bool => !markRequestIdUsed($id),
]);

if (!$evaluation->ok) {
    respond(403, ['error' => 'signup refused', 'reason' => $evaluation->reason?->value]);
}

// Create the account here.
respond(200, ['ok' => true]);
```

Try it with `php -S localhost:8000 signup.php` and a request ID from your browser integration: `curl -X POST localhost:8000 -d requestId=<requestId>`. With the placeholder key the SDK logs a warning that the key does not look like a Private API Key; use your own key.

Verify a webhook delivery (`webhook.php`):

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ShieldLabs\Event\IdentificationScoredEvent;
use ShieldLabs\Exception\SignatureVerificationException;
use ShieldLabs\Exception\WebhookParseException;
use ShieldLabs\Webhook;

$payload = (string) file_get_contents('php://input'); // the raw body, byte for byte
$signature = $_SERVER['HTTP_X_SHIELD_SIGNATURE'] ?? null;

try {
    $event = Webhook::constructEvent($payload, $signature, 'whsec_your_signing_secret'); // or getenv('SHIELDLABS_WEBHOOK_SECRET')
} catch (SignatureVerificationException) {
    http_response_code(401); // not signed with this endpoint's secret
    exit;
} catch (WebhookParseException) {
    http_response_code(400); // signed, but not a webhook event
    exit;
}

if ($event instanceof IdentificationScoredEvent) {
    $identification = $event->data; // the same Identification model the History API returns
    // Store it keyed by $identification->request_id so a repeated delivery is harmless.
}

http_response_code(200); // answer fast: the sender waits at most 1 second
```

A complete runnable app is in [`examples/`](examples/).

## Guide

### Waiting for the verdict

Scoring is asynchronous. The History row of an identification appears about 1-3 seconds after the browser call and can be refined for up to about 10 seconds while follow-up checks finish. Start the identification in the browser when the user begins the action (for example with `identifyOnInteraction()` from `@shieldlabs-ai/js`, which starts it when the signup form gets focus), not when the form is submitted, so the verdict is usually stored by the time your backend asks for it. `identifications->get()` polls for the row and returns the first version it sees. Its `timeout` option (10 seconds by default) is the total time budget of the call:

- **Schedule.** The first poll runs at once. The SDK then waits 250 ms, 500 ms, 1 s, 1.5 s and then 2 s between polls, and the last poll runs when the budget ends. With another `poll_interval` p the waits are p, 2p, 4p, 6p and then 8p, each at most 2 seconds, or at most p when p is longer: a 1-second interval waits 1, 2, 2, 2 seconds, and a 3-second interval polls every 3 seconds.
- **One request per poll.** Each poll is a single HTTP request without retries of its own. It may take the time left in the budget, but at least 1 second and never longer than the client's `timeout`.
- **Temporary errors keep the wait going.** After a 429, a 5xx response, a connection error or a timeout, the SDK polls again on the same schedule. If the last poll fails, its exception is thrown when the budget ends: `RateLimitException`, `ServerException`, `ApiConnectionException` or `ApiTimeoutException`.
- **Rate limits.** Inside the wait, a 429 is always followed by at least 1 second before the next poll: the SDK takes the longest of the next wait of the schedule, 1 second and `Retry-After` (up to 10 seconds). `Retry-After: 0` or a date in the past counts as 0, so it still waits 1 second. A wait that would pass the end of the budget is cut short, and the last poll runs then. When `Retry-After` (up to 10 seconds) asks for more than the time left, the `RateLimitException` is thrown at once. Calls outside the wait (`history->search()`, `history->iterate()` and `'wait' => false`) follow `Retry-After` as sent (see [Errors and retries](#errors-and-retries)).
- **Errors that stop at once.** Any other error, such as a 400, 401, 403 or 404, ends the wait at once and is thrown: a wrong request, key or base URL does not heal.
- **`null`** means the last poll found no identification for that request ID. Treat it as unverified, never as clean.

The limit per poll applies to the built-in `CurlClient`, whether the SDK created it or you passed it, and to the Guzzle or Symfony HttpClient that the SDK creates. Another PSR-18 client that you pass as `http_client` keeps its own timeout (PSR-18 has no way to change it per request), so with such a client the call can end up to one request timeout after the budget.

`null` also covers an identification that was never stored: when a visitor's IP goes over the per-IP limit of identifications, the browser still receives a request ID, but no row is written for it.

With `'wait' => false` the SDK makes one lookup, with the client's `timeout` and the usual retries. To read the refined version of a row, for example to log the final risk signals, make such a lookup again after about 10 seconds.

```php
$identification = $shieldlabs->identifications->get($requestId);                     // wait up to 10 s in total
$identification = $shieldlabs->identifications->get($requestId, ['timeout' => 3]);   // a 3-second budget
$identification = $shieldlabs->identifications->get($requestId, ['wait' => false]);  // one lookup, no polling
```

The agent also runs background identifications on its own (on clicks and navigation). Their request IDs never reach your page, so the History API can show extra rows for the same visitor. Always look up the request ID that arrived with the action.

### Deciding

An `Identification` carries:

- `risk_score`: 0-100. A value above 100 (999) is the rate-limit marker, never a score.
- the risk band from `$identification->riskBand()` or `Risk::band($score)`: trusted 0-29, suspicious 30-59, dangerous 60-100, plus `RiskBand::RateLimited` for the marker.
- `detection_flags`: 19 stable booleans such as `vpn`, `proxy`, `tor`, `anti_detect_browser` and `browser_automation`.
- `signals`: the weighted risk signals behind the score, for display and logging.

Branch on the score, the band and the flags. Signal names are an open set and weights can be negative, so never add them up yourself.

`Risk::evaluate()` runs the guard checks from our integration guides. The first failing check gives the reason:

| Order | Check | Reason |
|---|---|---|
| 1 | No identification | `missing` |
| 2 | `is_replay($requestId)` returns true (the SDK stores nothing itself) | `replayed` |
| 3 | Older than `max_age` seconds, by `observed_at` | `stale` |
| 4 | Score above 100 | `rate_limited` |
| 5 | Device ID is the all-zero UUID (no usable device signals) | `no_device_signals` |
| 6 | A flag from `block_flags` is set | `blocked_flag` (the flag is in `$evaluation->flag`) |
| 7 | The band is in `block_bands` | `blocked_band` |

Defaults: `max_age` 300, `block_bands` `[RiskBand::Dangerous]`, `block_flags` `['browser_automation', 'javascript_disabled']`. They are a starting point; tune them per protected action. For example, a login could ask for a second factor on the suspicious band:

```php
use ShieldLabs\Risk;
use ShieldLabs\RiskBand;

$evaluation = Risk::evaluate($identification, [
    'max_age' => 120,
    'block_flags' => ['browser_automation', 'javascript_disabled', 'tor'],
    'is_replay' => $isReplay,
]);

if (!$evaluation->ok) {
    // refuse, and log $evaluation->reason->value
} elseif ($evaluation->band === RiskBand::Suspicious) {
    // ask for a second factor
}
```

### Storing used request IDs

Pass `is_replay` so that one identification authorizes one action. The SDK stores nothing itself: record each request ID in your database with a unique key, and treat a duplicate-key error as "already used". Keep the rows a little longer than `max_age`, for example one day: older identifications are refused as `stale` anyway.

```php
// CREATE TABLE used_request_ids (request_id CHAR(36) PRIMARY KEY, used_at TIMESTAMP NOT NULL)
function markRequestIdUsed(PDO $pdo, string $requestId): bool
{
    try {
        $pdo->prepare('INSERT INTO used_request_ids (request_id, used_at) VALUES (?, CURRENT_TIMESTAMP)')
            ->execute([$requestId]);

        return true;
    } catch (PDOException $exception) {
        if (str_starts_with((string) $exception->getCode(), '23')) {
            return false; // integrity constraint violation: the request ID was used before
        }

        throw $exception;
    }
}

$evaluation = Risk::evaluate($identification, [
    'is_replay' => fn(string $id): bool => !markRequestIdUsed($pdo, $id),
]);
```

PDO throws exceptions by default since PHP 8.0. With Redis, `SET shieldlabs:used:<request ID> 1 NX EX 86400` does the same in one command: a null reply means the request ID was used before.

### Searching history for account-abuse checks

```php
use ShieldLabs\LookupType;

$page = $shieldlabs->history->search(LookupType::UserHid, $userHid, ['limit' => 50]);
$page->data;  // list of Identification, newest first
$page->total; // rows for this User HID across all pages
```

Lookup types: `ip` (dotted IPv4), `user_hid`, `visitor_id`, `request_id`, `device_id`, `session_id` and `cookie_id`. The SDK checks every lookup before sending it: an unknown type, a malformed UUID, an IPv6 address, an empty User HID or a `limit` outside 1-100 throws `ValidationException` and sends nothing.

A User HID is sent as one URL path segment in canonical form, so values with `@`, `+`, `=` or spaces (an email address, a base64 string) match exactly. A value that contains `/`, and the values `.` and `..`, cannot be searched and throw `ValidationException`. User HIDs from `UserHid::fromUserId()` are 64 hex characters and always work.

`history->iterate()` returns a lazy generator over every row. It pages with `offset`, skips rows it already returned (paging while new rows arrive can repeat a row) and stops at the total, at an empty page or after `max_items` rows (with `'max_items' => 0` it yields nothing and sends no request):

```php
// How many accounts has this device been used with?
$accounts = [];
if ($identification->hasDeviceSignals()) {
    $rows = $shieldlabs->history->iterate(LookupType::DeviceId, $identification->device_id, ['max_items' => 500]);
    foreach ($rows as $row) {
        if ($row->user_hid !== null && !in_array($row->user_hid, ['anonymous', 'fail', '-1', 'unknown'], true)) {
            $accounts[$row->user_hid] = true;
        }
    }
}

if (count($accounts) >= 3) {
    // send the signup to review
}
```

Look up `LookupType::UserHid` to count the devices behind one account, or `LookupType::Ip` for one address. Each page is one request, so keep the rate limit below in mind.

### Linking identifications to your users

Pass a User HID to the browser agent instead of a raw email or database ID, and compute it on the server:

```php
use ShieldLabs\UserHid;

$userHid = UserHid::fromUserId((string) $user->id, $userHidSecret);
// Render $userHid into the page and pass it to the browser SDK as the userId.
```

The result is HMAC-SHA256 as 64 lowercase hex characters: stable for a user and irreversible. Keep the secret on the server; changing it changes every User HID.

### Webhooks

- Verify the raw body: `file_get_contents('php://input')` in plain PHP, `$request->getContent()` in Laravel and Symfony. Never verify JSON that you decoded and encoded again: the server escapes `&` as `\u0026` and the bytes would differ.
- The header is `X-Shield-Signature: sha256=<hex>`, the HMAC-SHA256 of the raw body keyed with the full signing secret, `whsec_` prefix included.
- To rotate a secret without downtime, pass both: `Webhook::constructEvent($payload, $header, [$newSecret, $oldSecret])`. A delivery is valid when any secret matches.
- Events: `IdentificationScoredEvent` (`$event->data` is an `Identification`), `WebhookPingEvent` (sent when you verify an endpoint) and `UnknownWebhookEvent` for event types newer than this SDK (acknowledge them with a 2xx). `SignatureVerificationException` means the delivery is not authentic; `WebhookParseException` means a correctly signed body is not a valid event.
- Current failed deliveries retry within a bounded window; store the verified event before 2xx and deduplicate by event_id.
- For guaranteed reads, use the History API: retries can exhaust, and a History row can be refined after its webhook was sent.
- The "Test" button in the analytics dashboard sends a sample with 17 of the 19 flags and second-precision timestamps. It parses normally; missing flags are false.
- `Webhook::verifySignature()` returns a boolean without parsing, for custom flows.

### Domain profile (Management API)

```php
use ShieldLabs\ShieldLabsManagement;

$management = new ShieldLabsManagement([
    'secret_key' => 'your_secret_key', // or SHIELDLABS_SECRET_KEY
    'domain' => 'example.com',         // or SHIELDLABS_DOMAIN; scheme, path and "www." are removed
]);

$profile = $management->getProfile();
$profile->remaining_identifications; // negative when the account is over its included volume
$profile->public_key_masked;         // "****************************a3f8"
$profile->created_at;                // DateTimeImmutable in UTC
```

The Management API allows about 15 requests per minute per client IP and then blocks that IP for 10 minutes. The client never retries a 429: cache the profile rather than reading it on every request.

### Errors and retries

Every exception extends `ShieldLabs\Exception\ShieldLabsException`:

| Exception | When | Retried |
|---|---|---|
| `ValidationException` | Invalid arguments or options, before any request | No |
| `BadRequestException` | HTTP 400 | No |
| `AuthenticationException` | HTTP 401 or 403 (wrong, disabled or mismatched key) | No |
| `QuotaExceededException` | HTTP 402 (no identifications left) | No |
| `NotFoundException` | HTTP 404 (often a wrong base URL) | No |
| `RateLimitException` | HTTP 429 | History API: yes. Management API: never |
| `ServerException` | HTTP 5xx, including edge proxy pages | Yes |
| `ApiException` | Any other status (the parent of the classes above) | No |
| `ApiConnectionException` | The request could not be completed | Yes |
| `ApiTimeoutException` | An attempt ran past the timeout | Yes |
| `SignatureVerificationException` | A webhook signature is missing, malformed or wrong | Not applicable |
| `WebhookParseException` | A verified webhook body is not a valid event | Not applicable |

`ApiException` has `getStatusCode()`, `getBody()` (decoded JSON, or the raw text), `getRawBody()`, `getErrorMessage()` and `getHeaders()`; `RateLimitException::getRetryAfter()` gives the `Retry-After` value in seconds. Error bodies differ between endpoints (empty, a bare JSON string, an object, plain text), and the SDK parses all of them without failing.

Error messages never include keys or secrets. A key, secret or domain with characters that cannot be sent in an HTTP header (line breaks, NUL, spaces, non-ASCII) throws `ValidationException` when the client is created, without repeating the value.

Retries use exponential backoff with jitter (0.5 s base, doubling, at most 8 s), for at most `max_retries` retries (2 by default). When a 429 or 5xx response carries `Retry-After`, the retry follows it as sent, up to 10 seconds: `Retry-After: 0` or a date in the past retries at once. A 429 without `Retry-After` is retried after at least 1 second, because the History API counts requests per second. `identifications->get()` sends no retries while it waits: the next poll takes their place, and after a 429 it waits at least 1 second whatever `Retry-After` says (see [Waiting for the verdict](#waiting-for-the-verdict)).

### Rate limits

| API | Limit | What the SDK does |
|---|---|---|
| History API | About 15 requests per second per domain, shared by everything that uses the domain's key | Retries a 429 after `Retry-After` as sent (up to 10 seconds), or after at least 1 second when it has none. While `identifications->get()` waits, a 429 waits at least 1 second, or longer when the schedule or `Retry-After` asks for more |
| Management API | About 15 requests per minute per client IP, then a 10-minute block | Never retries a 429; cache the results |

### HTTP client and timeouts

`timeout` (10 seconds by default) limits every HTTP attempt the SDK sends. While `identifications->get()` waits, a poll is also limited to the time left in its budget (at least 1 second) when the SDK can change the client's timeout. With no `http_client` option, the SDK picks the client that applies it:

1. Guzzle 7 or Symfony HttpClient, when `php-http/discovery` finds one of them in your project. The SDK creates its own instance with the timeout set and redirects turned off.
2. Otherwise its built-in client on ext-curl, `ShieldLabs\Http\CurlClient`, which never follows redirects and only speaks HTTP and HTTPS.
3. Without ext-curl, any other PSR-18 client that discovery finds. The SDK cannot pass the timeout to it, so it logs a warning; pass a configured client instead.

A client that you pass as `http_client` is used as is, with its own settings, so give it a timeout. With the built-in `CurlClient` a poll still gets the shorter timeout described above; with another client each poll can take that client's full timeout:

```php
$shieldlabs = new ShieldLabs([
    'api_key' => 'sec_your_private_key',
    'http_client' => new GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 3]),
]);

// Or always use the built-in client, whatever else is installed:
$shieldlabs = new ShieldLabs([
    'api_key' => 'sec_your_private_key',
    'http_client' => new ShieldLabs\Http\CurlClient(timeout: 10.0),
]);
```

The SDK writes its warnings (an API key in an unexpected format, a timeout it cannot apply, a plain http base URL allowed with `allow_insecure_http`) to the PHP error log with `error_log()`, so they never end up in a response. Each warning is logged once per process; under PHP-FPM or `php -S` that is once per request that creates a client.

A client keeps no mutable state after construction: share one instance, for example as a singleton in your service container, including in long-running PHP workers.

### Configuration

`new ShieldLabs(array $options)`:

| Option | Default | Notes |
|---|---|---|
| `api_key` | `SHIELDLABS_API_KEY` | Private API Key, visible ASCII characters only. Required. The SDK logs a warning (and never fails) when it does not look like `sec_xxxxxxxx-xxxxxxxx-xxxxxxxx`; the key itself is never logged |
| `base_url` | `SHIELDLABS_API_BASE_URL`, else `https://account.shieldlabs.ai` | Origin of the History API; a trailing `/api` is removed, so requests never go to `/api/api/...`. It must use https; plain http is accepted for `localhost`, `127.0.0.1` and `[::1]` |
| `allow_insecure_http` | `false` | Accept a plain http `base_url` on another host, for a test server. The key then travels unencrypted, and the SDK logs a warning. Without it such a URL throws `ValidationException` |
| `timeout` | `10` | Seconds per HTTP attempt. Applied to the client the SDK creates; an `http_client` you pass keeps its own settings. While `identifications->get()` waits, a poll is limited to the time left (at least 1 second) where the client allows it |
| `max_retries` | `2` | Retries for connection errors, timeouts, 429 and 5xx |
| `http_client` | Guzzle or Symfony HttpClient when installed, else `CurlClient` | Any `Psr\Http\Client\ClientInterface`, used as is |
| `request_factory` | discovered | Any `Psr\Http\Message\RequestFactoryInterface` |

`new ShieldLabsManagement(array $options)` takes `secret_key` (`SHIELDLABS_SECRET_KEY`, visible ASCII characters only), `domain` (`SHIELDLABS_DOMAIN`; an international domain in its punycode form, `xn--...`), `base_url` (`SHIELDLABS_MANAGEMENT_BASE_URL`, else `https://api.shieldlabs.ai`; a trailing `/v1` is removed; https, or plain http on a loopback host) and the same `allow_insecure_http`, `timeout`, `max_retries`, `http_client` and `request_factory`. Unknown option names throw `ValidationException`. A key, domain or URL option that is missing, `null` or `false` (what `getenv()` returns for an unset variable) falls back to its environment variable.

## Reference

| API | Returns |
|---|---|
| `new ShieldLabs(array $options = [])` | History API client |
| `$client->identifications->get(string $requestId, array $options = [])` | `?Model\Identification`. Options: `wait` (true), `timeout` (10, the total budget in seconds), `poll_interval` (0.25, the first wait; the later waits are 2, 4, 6 and 8 times it, each at most 2 s, or at most `poll_interval` when that is longer) |
| `$client->history->search(LookupType\|string $type, string $value, array $options = [])` | `Model\HistoryPage`. Options: `limit` (20, 1-100), `offset` (0). A `user_hid` value cannot contain `/` |
| `$client->history->iterate(LookupType\|string $type, string $value, array $options = [])` | `Generator` of `Model\Identification`. Options: `page_size` (100), `max_items` (no limit; 0 yields nothing) |
| `new ShieldLabsManagement(array $options = [])` | Management API client |
| `$management->getProfile()` | `Model\DomainProfile` |
| `$management->getDomain()`, `ShieldLabsManagement::normalizeDomain(string $domain)` | The normalized domain |
| `Webhook::verifySignature(string $payload, ?string $header, string\|array $secret)` | `bool` |
| `Webhook::constructEvent(string $payload, ?string $header, string\|array $secret)` | `Event\IdentificationScoredEvent\|Event\WebhookPingEvent\|Event\UnknownWebhookEvent` |
| `Risk::band(int $score)`, `RiskBand::fromScore(int $score)` | `RiskBand` |
| `Risk::isRateLimited(int $score)` | `bool` |
| `Risk::evaluate(?Model\Identification $identification, array $options = [])` | `Evaluation` (`ok`, `reason`, `band`, `flag`) |
| `UserHid::fromUserId(string $userId, string $secret)` | `string` (64 hex characters) |
| `Http\CurlClient(float $timeout = 10.0, ?float $connectTimeout = null)` | The built-in PSR-18 client. `getTimeout()`; `withTimeout(float $timeout)` returns a copy with another timeout |

| Model | Properties |
|---|---|
| `Model\Identification` | `request_id`, `visitor_id`, `device_id`, `session_id`, `cookie_id`, `user_hid` (`?string`; `"anonymous"` for anonymous checks), `domain`, `public_ip` and `local_ip` (`IpInfo`), `connection_type`, `os`, `browser`, `device_type`, `traffic_source` (`TrafficSource`), `risk_score` (`int`), `signals` (list of `Signal`), `detection_flags` (`DetectionFlags`), `observed_at` (`?DateTimeImmutable`, UTC), `source` (`"webhook"` or `"history"`), `raw` (the payload as received). Methods: `riskBand()`, `isRateLimited()`, `hasDeviceSignals()`, `toArray()` |
| `Model\IpInfo` | `ip` (`""` when unknown), `country` (English country name such as `"Germany"`, `""` when unknown) |
| `Model\TrafficSource` | `channel`, `referrer_domain`, `landing_url`, `click_id_type`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` |
| `Model\Signal` | `name`, `weight` (`int`, can be negative), `description` (History API rows only) |
| `Model\DetectionFlags` | 19 booleans; `get(string $name)`, `active()`, `toArray()` |
| `Model\HistoryPage` | `data` (list of `Identification`), `total` |
| `Model\DomainProfile` | `domain`, `remaining_identifications`, `public_key_masked`, `secret_key_masked`, `created_at`, `raw` |
| `Event\IdentificationScoredEvent` | `event_type`, `schema_version`, `created_at`, `data` (`Identification`), `raw` |
| `Event\WebhookPingEvent`, `Event\UnknownWebhookEvent` | `event_type`, `schema_version`, `created_at`, `raw` |

Enums: `LookupType`, `RiskBand` (`Trusted`, `Suspicious`, `Dangerous`, `RateLimited`) and `EvaluationReason`. Constants: `SignalName` (known signal names), `ConnectionType` (known connection types), `ShieldLabs::VERSION`. All classes live in the `ShieldLabs\` namespace.

## Compatibility

- PHP 8.1, 8.2, 8.3, 8.4 and 8.5.
- Any PSR-18 client (Guzzle 7, Symfony HttpClient and others), or the built-in cURL client.
- Webhook schema version `2026-06-01`. Unknown fields, event types and schema versions are accepted; new signal names arrive as plain strings.
- Semantic Versioning: breaking changes only in a new major version.

## Development

Refresh the generated client when the API description changes. This does not replace the supported library in this repository.

```bash
./sync.sh      # download the current OpenAPI description into resources/
./generate.sh  # rebuild generated/ from that file
```


```bash
composer install
composer test      # PHPUnit: unit, shared fixture and integration tests
composer analyse   # PHPStan at the max level
composer cs        # php-cs-fixer (PER coding style); composer cs:fix applies it
```

Without a local PHP:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install && composer check"
```

`tests/data/` holds the shared test fixtures that every ShieldLabs server SDK passes. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Support

- Documentation: [docs.shieldlabs.ai](https://docs.shieldlabs.ai)
- Analytics dashboard: [app.shieldlabs.ai](https://app.shieldlabs.ai)
- Email: [contact@shieldlabs.ai](mailto:contact@shieldlabs.ai)

## License

[MIT](LICENSE)


### Webhook contract 2026-10-06

Current events include signed `event_id`, optional `site_id`, the complete `data.risk_events`
catalogue (including zero-weight events), `data.fingerprint` (FP21 hardware ID distinct from
`device_id`), and `data.hre` for sharing/takeover/travel with explicit statuses. Older envelopes
remain supported. Only identification risk score is sent; AI bots/browser are planned only,
and all-time entity risks are excluded.

Persist the verified event in a durable inbox **before** returning 2xx and deduplicate by
`event_id` (legacy scored bodies: `data.request_id`). Timeout/network/429/5xx retry with
backoff in a bounded window (8 failed sends / 15-minute retry age), then DLQ. Other 4xx are
terminal. Retried bodies and event IDs stay unchanged. `X-Shield-Event-Id` mirrors the body ID;
trust the signed body. Signature verification remains raw-body HMAC-SHA256. Delivery is not
exactly-once and later History corrections do not automatically create a new webhook event.
