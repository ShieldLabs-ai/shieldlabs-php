<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ShieldLabs\Client;

final class VerifyWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const BODY = '{"event_type":"webhook.ping","schema_version":"2026-06-01","created_at":"2026-06-26T14:20:42Z"}';

    private function sign(string $secret, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testAcceptsValidSignature(): void
    {
        $this->assertTrue(Client::verifyWebhook(self::BODY, $this->sign(self::SECRET, self::BODY), self::SECRET));
    }

    public function testRejectsWrongSecret(): void
    {
        $this->assertFalse(Client::verifyWebhook(self::BODY, $this->sign(self::SECRET, self::BODY), 'other'));
    }

    public function testRejectsTamperedBody(): void
    {
        $this->assertFalse(Client::verifyWebhook(self::BODY . ' ', $this->sign(self::SECRET, self::BODY), self::SECRET));
    }

    public function testRejectsMissingHeader(): void
    {
        $this->assertFalse(Client::verifyWebhook(self::BODY, '', self::SECRET));
    }

    public function testRejectsTruncatedSignature(): void
    {
        $this->assertFalse(Client::verifyWebhook(self::BODY, 'sha256=ab', self::SECRET));
    }

    public function testRejectsEmptySecret(): void
    {
        $this->assertFalse(Client::verifyWebhook(self::BODY, $this->sign('x', self::BODY), ''));
    }
}
