<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\UserHid;

final class UserHidTest extends TestCase
{
    public function testIsTheLowercaseHexHmacOfTheUserId(): void
    {
        // HMAC-SHA256(key = "user-hid-secret", message = "user-42"), computed independently.
        self::assertSame(
            '5918c3915fde12fc9616dc42c6e88b91dba5886ec4365000c9b4172148ab3972',
            UserHid::fromUserId('user-42', 'user-hid-secret'),
        );
    }

    public function testMatchesAKnownVector(): void
    {
        // RFC 4231 test case 2: key "Jefe", data "what do ya want for nothing?".
        self::assertSame(
            '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843',
            UserHid::fromUserId('what do ya want for nothing?', 'Jefe'),
        );
    }

    public function testIsStableAndDependsOnTheSecret(): void
    {
        $a = UserHid::fromUserId('customer@example.com', 'secret-a');

        self::assertSame($a, UserHid::fromUserId('customer@example.com', 'secret-a'));
        self::assertNotSame($a, UserHid::fromUserId('customer@example.com', 'secret-b'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function testRejectsAnEmptyUserId(): void
    {
        $this->expectException(ValidationException::class);
        UserHid::fromUserId('', 'secret');
    }

    public function testRejectsAnEmptySecret(): void
    {
        $this->expectException(ValidationException::class);
        UserHid::fromUserId('user-42', '');
    }
}
