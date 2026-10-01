<?php

declare(strict_types=1);

namespace ShieldLabs;

use ShieldLabs\Exception\ValidationException;

/**
 * Server-side User HID: a stable, irreversible identifier for one of your accounts,
 * to pass to the browser agent instead of a raw email or database ID.
 */
final class UserHid
{
    private function __construct() {}

    /**
     * HMAC-SHA256 of the user ID keyed with your secret, as 64 lowercase hex
     * characters. Keep the secret on the server and never change it, or every
     * User HID changes with it.
     *
     * @throws ValidationException when the user ID or the secret is empty
     */
    public static function fromUserId(string $userId, string $secret): string
    {
        if ($userId === '') {
            throw new ValidationException('The user ID must be a non-empty string.');
        }
        if ($secret === '') {
            throw new ValidationException('The User HID secret must be a non-empty string.');
        }

        return hash_hmac('sha256', $userId, $secret);
    }
}
