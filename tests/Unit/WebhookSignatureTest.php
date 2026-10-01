<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\SignatureVerificationException;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Webhook;

final class WebhookSignatureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string|list<string>, bool}>
     */
    public static function vectors(): iterable
    {
        $fixture = Fixtures::json('webhook-signature-vectors.json');
        \assert(\is_array($fixture['vectors']));
        foreach ($fixture['vectors'] as $vector) {
            \assert(\is_array($vector) && \is_string($vector['name']) && \is_string($vector['body_base64']));
            \assert(\is_string($vector['signature_header']) && \is_bool($vector['valid']));
            $body = base64_decode($vector['body_base64'], true);
            \assert(\is_string($body));
            $secret = $vector['secrets'] ?? $vector['secret'];
            \assert(\is_string($secret) || \is_array($secret));
            /** @var string|list<string> $secret */
            yield $vector['name'] => [$body, $vector['signature_header'], $secret, $vector['valid']];
        }
    }

    public function testHasAllSharedVectors(): void
    {
        self::assertCount(21, iterator_to_array(self::vectors()));
    }

    /**
     * @param string|list<string> $secret
     */
    #[DataProvider('vectors')]
    public function testVerifiesTheSharedVector(string $body, string $header, string|array $secret, bool $valid): void
    {
        self::assertSame($valid, Webhook::verifySignature($body, $header, $secret));
    }

    /**
     * @param string|list<string> $secret
     */
    #[DataProvider('vectors')]
    public function testConstructEventAgreesWithTheVector(string $body, string $header, string|array $secret, bool $valid): void
    {
        if ($valid) {
            $event = Webhook::constructEvent($body, $header, $secret);
            self::assertNotSame('', $event->event_type);

            return;
        }

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($body, $header, $secret);
    }

    public function testBodyFieldMatchesTheBase64Bytes(): void
    {
        $fixture = Fixtures::json('webhook-signature-vectors.json');
        \assert(\is_array($fixture['vectors']));
        foreach ($fixture['vectors'] as $vector) {
            \assert(\is_array($vector));
            self::assertSame($vector['body'], base64_decode((string) $vector['body_base64'], true), (string) $vector['name']);
        }
    }

    public function testRejectsAMissingHeader(): void
    {
        self::assertFalse(Webhook::verifySignature('{}', null, 'whsec_x'));
    }

    public function testRejectsAnEmptySecretList(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');
        $header = 'sha256=' . hash_hmac('sha256', $body, 'whsec_x');

        self::assertTrue(Webhook::verifySignature($body, $header, ['whsec_x']));
        self::assertFalse(Webhook::verifySignature($body, $header, []));
        self::assertFalse(Webhook::verifySignature($body, $header, ['']));
    }

    public function testIgnoresEmptyAndNonStringSecretsInAList(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');
        $header = 'sha256=' . hash_hmac('sha256', $body, 'whsec_rotated');

        /** @var list<string> $secrets */
        $secrets = ['', 42, 'whsec_rotated'];
        self::assertTrue(Webhook::verifySignature($body, $header, $secrets));
    }

    public function testRequiresTheLowercasePrefix(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');
        $digest = hash_hmac('sha256', $body, 'whsec_x');

        self::assertFalse(Webhook::verifySignature($body, 'SHA256=' . $digest, 'whsec_x'));
        self::assertFalse(Webhook::verifySignature($body, 'sha256=' . $digest . 'ab', 'whsec_x'));
        self::assertFalse(Webhook::verifySignature($body, 'sha256=' . $digest . "\nx", 'whsec_x'));
    }
}
