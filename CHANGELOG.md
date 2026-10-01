# Changelog

All notable changes to this package are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `sync.sh` downloads the OpenAPI description and `generate.sh` rebuilds `generated/` from it. The supported client is unchanged.

## [1.0.0] - 2026-09-30

First stable release, published as `shieldlabs/shieldlabs-php`. It replaces the
earlier preview package and its API.

### Added

- `ShieldLabs\ShieldLabs`: History API client for the Private API Key.
  - `identifications->get($requestId)` waits for the verdict within a total time
    budget (`timeout`, 10 s by default). It polls at once, then after waits of
    250 ms, 500 ms, 1 s, 1.5 s and 2 s (1, 2, 4, 6 and 8 times `poll_interval`,
    each at most 2 s, or at most `poll_interval` when that is longer, so 3 s polls
    every 3 s), and one last time when the budget ends. Each poll is one HTTP
    request without retries, limited to the time left but at least 1 s. A 429, a
    5xx response, a connection error or a timeout keeps the polling going, and
    such an error is thrown only when the last poll failed. After a 429 the next
    poll waits at least 1 s: the longest of the next scheduled wait, 1 s and
    `Retry-After` (up to 10 s; `Retry-After: 0` or a past date counts as 0), cut
    short when the budget ends; when `Retry-After` is longer than the time left,
    the 429 is thrown at once. 400, 401, 403, 404 and other errors end the wait at
    once.
  - `history->search($type, $value)` and the lazy `history->iterate()` generator,
    which de-duplicates rows on `request_id` and stops after `max_items` rows
    (`0` yields nothing and sends no request).
  - Client-side validation of lookup types, UUIDs, IPv4 addresses, `limit` and
    `offset`: invalid input throws `ValidationException` and sends nothing.
  - User HID lookups are sent in canonical path form (`@`, `+`, `=`, `:` and
    similar characters stay as they are), so such values match. A User HID that
    contains `/`, or is `.` or `..`, throws `ValidationException`, because the
    History API cannot search it.
- `ShieldLabs\ShieldLabsManagement::getProfile()`: domain profile from the
  Management API, with domain normalization. It never retries a 429.
- `ShieldLabs\Webhook::verifySignature()` and `Webhook::constructEvent()`: signature
  checks over the raw body with one secret or a list (for rotation), and typed
  events (`IdentificationScoredEvent`, `WebhookPingEvent`, `UnknownWebhookEvent`).
- One `Identification` model for webhook payloads and History rows, with typed
  `IpInfo`, `TrafficSource`, `Signal` and `DetectionFlags` (19 flags; missing flags
  are false).
- `Risk::band()`, `Risk::isRateLimited()`, `Risk::evaluate()` (freshness, replay,
  rate-limit marker, device signals, flags and bands) and `UserHid::fromUserId()`.
- Exception hierarchy under `ShieldLabs\Exception`, including
  `QuotaExceededException` (402) and `RateLimitException::getRetryAfter()`.
- Keys, secrets and domains are checked when a client is created: a value with
  characters that cannot be sent in an HTTP header (line breaks, NUL, spaces,
  non-ASCII) throws `ValidationException`, and no error message repeats a key or
  secret. The built-in cURL client refuses header lines with line breaks.
- Retries with exponential backoff and jitter for connection errors, timeouts, 429
  (History API) and 5xx, following `Retry-After` as sent (up to 10 s). A 429
  without `Retry-After` waits at least 1 s. A timeout or a dropped connection while
  the response body is read (Symfony HttpClient streams it) counts as a timeout or
  connection error too.
- Base URLs must use https. Plain http is accepted for `localhost`, `127.0.0.1`
  and `[::1]`, and for other hosts only with the `allow_insecure_http` option, so a
  mistyped URL never sends a key unencrypted.
- HTTP client selection that applies the `timeout` option by default: a Guzzle 7 or
  Symfony HttpClient found through PSR-18 discovery is created with the timeout,
  otherwise the built-in cURL client is used. A client passed as `http_client` is used
  as is. The built-in `Http\CurlClient` has `getTimeout()` and `withTimeout()`; with
  it, and with the Guzzle or Symfony client the SDK creates, a poll of
  `identifications->get()` gets the shorter timeout.
- SDK warnings, such as an API key in an unexpected format, go to the PHP error log
  through `error_log()`, once per process, and never into a response.
- Runnable example: `examples/server.php` (signup guard and webhook receiver).

### Fixed

- Requests go to `https://account.shieldlabs.ai/api/v1/history/...`; the preview
  built `/api/api/...` paths. A base URL ending in `/api` is now accepted and
  normalized.

### Removed

- The preview `ShieldLabs\Client` class and the `shieldlabs/shieldlabs` package name.

[1.0.0]: https://github.com/ShieldLabs-ai/shieldlabs-php/releases/tag/v1.0.0
