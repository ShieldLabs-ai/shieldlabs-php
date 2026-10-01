<?php

declare(strict_types=1);

namespace ShieldLabs\Internal;

/**
 * Logs SDK warnings with error_log(), each message once per process. The message
 * goes to the PHP error log (the server log, or stderr of `php -S` and the CLI), so
 * it never ends up in an HTTP response, even with display_errors on, and an error
 * handler that turns warnings into exceptions never sees it. Messages never contain
 * keys or secrets.
 *
 * @internal
 */
final class Warning
{
    public const PREFIX = 'shieldlabs-php: ';

    /** @var array<string, true> */
    private static array $logged = [];

    private function __construct() {}

    public static function once(string $message): void
    {
        if (isset(self::$logged[$message])) {
            return;
        }
        self::$logged[$message] = true;
        error_log(self::PREFIX . $message);
    }

    /**
     * Forgets which messages were logged. For tests.
     */
    public static function reset(): void
    {
        self::$logged = [];
    }
}
