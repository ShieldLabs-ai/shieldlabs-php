<?php

declare(strict_types=1);

namespace ShieldLabs\Tests;

use PHPUnit\Framework\TestCase;
use ShieldLabs\Model\Identification;

final class ClientIdentityTest extends TestCase
{
    public function testScopeParityAndUnknownValues(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../test-data-client-identity.json'), true, 512, \JSON_THROW_ON_ERROR);
        $history = Identification::fromHistoryRow(['score' => 70, 'client_identity' => $fixture]);
        $webhook = Identification::fromWebhookData(['risk_score' => 70, 'client_identity' => $fixture]);
        self::assertEquals($history->client_identity, $webhook->client_identity);
        self::assertSame('provider', $history->client_identity->verified[0]['subject']);
        self::assertSame('GPTBot', $history->client_identity->claims[0]['agent_name']);
        self::assertSame(70, $history->risk_score);
        self::assertSame($fixture, $history->toArray()['client_identity']);
        $fixture['availability'] = 'future_state';
        $fixture['extra'] = 'kept';
        self::assertSame($fixture, Identification::fromHistoryRow(['client_identity' => $fixture])->toArray()['client_identity']);
        foreach ([null, 'bad', []] as $value) {
            $id = Identification::fromHistoryRow(['client_identity' => $value]);
            self::assertNull($id->client_identity);
            self::assertArrayNotHasKey('client_identity', $id->toArray());
        }
    }
}
