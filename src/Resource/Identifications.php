<?php

declare(strict_types=1);

namespace ShieldLabs\Resource;

use ShieldLabs\Exception\ApiConnectionException;
use ShieldLabs\Exception\ApiTimeoutException;
use ShieldLabs\Exception\RateLimitException;
use ShieldLabs\Exception\ServerException;
use ShieldLabs\Exception\ShieldLabsException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\Internal\Clock;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\Transport;
use ShieldLabs\Internal\Validate;
use ShieldLabs\LookupType;
use ShieldLabs\Model\Identification;

/**
 * Read the verdict for the request ID your frontend received. Available as
 * `$client->identifications`.
 */
final class Identifications
{
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_POLL_INTERVAL = 0.25;

    /**
     * Longest wait between two polls for a poll interval up to 2 s. A longer poll
     * interval is its own cap: each wait is at most max(2 s, poll interval).
     */
    public const MAX_POLL_WAIT = 2.0;

    /** Shortest time a poll may take, even when less of the budget is left. */
    public const MIN_ATTEMPT_TIMEOUT = 1.0;

    /** Multipliers of the poll interval: 250 ms, 500 ms, 1 s, 1.5 s, then 2 s with the defaults. */
    private const POLL_STEPS = [1, 2, 4, 6, 8];

    /**
     * @internal use {@see \ShieldLabs\ShieldLabs::$identifications}
     */
    public function __construct(
        private readonly History $history,
        private readonly Clock $clock,
    ) {}

    /**
     * The identification for a request ID, or null when none exists (yet).
     *
     * Scoring is asynchronous: the row appears about 1-3 s after the browser call and
     * can be refined for up to about 10 s while follow-up checks finish; this returns
     * the first version it sees. With `wait` (the default) the SDK polls within a total
     * budget of `timeout` seconds:
     *
     * - the first poll is immediate, then it waits 250 ms, 500 ms, 1 s, 1.5 s and then
     *   2 s between polls, and the last poll runs when the budget ends. With another
     *   `poll_interval` p the waits are p, 2p, 4p, 6p and then 8p, each at most 2 s or
     *   at most p when p is longer: 1 s gives 1, 2, 2, 2 s, and 3 s polls every 3 s;
     * - each poll is one HTTP request without retries, limited to the time left but at
     *   least 1 s (and never longer than the client timeout) where the HTTP client
     *   allows it: the built-in CurlClient and the clients the SDK creates do, another
     *   client passed as `http_client` keeps its own timeout;
     * - a 429, a 5xx response, a connection error or a timeout does not end the wait.
     *   After a 429 the next poll waits at least 1 s: the longest of the next scheduled
     *   wait, 1 s and Retry-After (at most 10 s; 0 or a date in the past counts as 0),
     *   cut short when the budget ends, and the last poll runs then. When Retry-After
     *   asks for more than the time left, the 429 is thrown at once;
     * - when the budget ends, the error of the last poll is thrown if that poll failed,
     *   otherwise null is returned;
     * - any other error, such as 400, 401, 403 or 404, is thrown at once.
     *
     * Null means "unverified", never "clean". With `wait` false the SDK makes one
     * lookup with the usual timeout and retries.
     *
     * Options: `wait` (default true), `timeout`, the total budget in seconds (default
     * 10), and `poll_interval`, the first wait in seconds (default 0.25; no scheduled
     * wait is longer than 2 s or than `poll_interval`, whichever is longer).
     *
     * @param array{wait?: bool, timeout?: int|float, poll_interval?: int|float} $options
     *
     * @throws ValidationException before any request when the request ID is not a UUID
     * @throws ShieldLabsException
     */
    public function get(string $requestId, array $options = []): ?Identification
    {
        Options::assertKnown($options, ['wait', 'timeout', 'poll_interval'], 'identifications->get()');
        $requestId = Validate::uuid($requestId, 'request ID');
        $wait = Options::boolean($options, 'wait', true);
        $timeout = Options::seconds($options, 'timeout', self::DEFAULT_TIMEOUT, true);
        $pollInterval = Options::seconds($options, 'poll_interval', self::DEFAULT_POLL_INTERVAL, false);

        if (!$wait) {
            return $this->history->fetch(LookupType::RequestId, $requestId, 1, 0)->data[0] ?? null;
        }

        $deadline = $this->clock->now() + $timeout;
        $lastPoll = false;
        for ($step = 0; ; ++$step) {
            $attemptTimeout = max($deadline - $this->clock->now(), self::MIN_ATTEMPT_TIMEOUT);
            $error = null;
            try {
                $page = $this->history->fetch(LookupType::RequestId, $requestId, 1, 0, 0, $attemptTimeout);
                if ($page->data !== []) {
                    return $page->data[0];
                }
            } catch (RateLimitException|ServerException|ApiConnectionException|ApiTimeoutException $exception) {
                // These can pass while the budget lasts; any other error ends the wait here.
                $error = $exception;
            }

            $remaining = $deadline - $this->clock->now();
            if ($lastPoll || $remaining <= 0) {
                if ($error !== null) {
                    throw $error;
                }

                return null;
            }

            $delay = self::pollDelay($step, $pollInterval);
            if ($error instanceof RateLimitException) {
                // No Retry-After, Retry-After: 0 and a date in the past all count as 0 s;
                // the 1 s floor applies in every case.
                $retryAfter = min(max($error->getRetryAfter() ?? 0.0, 0.0), Transport::RETRY_AFTER_CAP);
                if ($retryAfter > $remaining) {
                    throw $error;
                }
                $delay = max($delay, Transport::RATE_LIMIT_MIN_WAIT, $retryAfter);
            }

            // A wait that reaches the deadline is cut short, and the poll after it is the last.
            $lastPoll = $delay >= $remaining;
            $this->clock->sleep(min($delay, $remaining));
        }
    }

    /**
     * Wait before the poll that follows poll number $step (0-based): the poll
     * interval times 1, 2, 4, 6, then 8, each at most max(2 s, poll interval), so
     * a poll interval longer than 2 s is used as it is.
     */
    public static function pollDelay(int $step, float $pollInterval): float
    {
        $multiplier = self::POLL_STEPS[min(max($step, 0), \count(self::POLL_STEPS) - 1)];

        return min($pollInterval * $multiplier, max(self::MAX_POLL_WAIT, $pollInterval));
    }
}
