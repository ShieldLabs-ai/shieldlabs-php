<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Integration;

use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Http\CurlClient;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Tests\Support\PhpServer;

/**
 * Runs examples/server.php against a local stand-in for the History API.
 */
final class ExampleServerTest extends TestCase
{
    private const SECRET = 'whsec_00112233445566778899aabbccddeeff';
    private const TRUSTED = '11111111-1111-4111-8111-111111111111';

    private static ?PhpServer $api = null;
    private static ?PhpServer $app = null;

    /** The app as the instructions start it: placeholder key, PHP's default error display. */
    private static ?PhpServer $documentedApp = null;
    private static string $documentedTempDir = '';

    public static function setUpBeforeClass(): void
    {
        @unlink(sys_get_temp_dir() . '/shieldlabs-example-keys.json');
        self::$api = new PhpServer(__DIR__ . '/servers/mock-api.php');
        self::$app = new PhpServer(\dirname(__DIR__, 2) . '/examples/server.php', [
            'SHIELDLABS_API_KEY' => Clients::API_KEY,
            'SHIELDLABS_API_BASE_URL' => self::$api->url . '/api',
            'SHIELDLABS_WEBHOOK_SECRET' => 'whsec_previous_secret_value, ' . self::SECRET,
        ]);

        self::$documentedTempDir = sys_get_temp_dir() . '/shieldlabs-example-' . bin2hex(random_bytes(6));
        mkdir(self::$documentedTempDir);
        self::$documentedApp = new PhpServer(
            \dirname(__DIR__, 2) . '/examples/server.php',
            [
                'SHIELDLABS_API_KEY' => 'sec_your_private_key',
                'SHIELDLABS_API_BASE_URL' => self::$api->url,
                'SHIELDLABS_WEBHOOK_SECRET' => 'whsec_your_signing_secret',
            ],
            // Without a php.ini, PHP prints errors into the response (display_errors=1).
            ['display_errors' => '1', 'error_reporting' => '-1', 'sys_temp_dir' => self::$documentedTempDir],
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->stop();
        self::$documentedApp?->stop();
        self::$api?->stop();
        self::$app = null;
        self::$documentedApp = null;
        self::$api = null;
        @unlink(sys_get_temp_dir() . '/shieldlabs-example-keys.json');
        @unlink(self::$documentedTempDir . '/shieldlabs-example-keys.json');
        @rmdir(self::$documentedTempDir);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, array<mixed>, string}
     */
    private static function post(string $path, string $body, array $headers = ['Content-Type' => 'application/json'], ?PhpServer $app = null): array
    {
        $app ??= self::$app;
        \assert($app !== null);
        $response = (new CurlClient(20.0))->sendRequest(new Request('POST', $app->url . $path, $headers, $body));
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);

        return [$response->getStatusCode(), \is_array($decoded) ? $decoded : ['raw' => $raw], $raw];
    }

    private static function log(): string
    {
        \assert(self::$app !== null);

        return self::$app->log();
    }

    public function testAcceptsATrustedSignupAndRefusesItsReuse(): void
    {
        [$status, $body] = self::post('/signup', json_encode(['requestId' => self::TRUSTED], \JSON_THROW_ON_ERROR));
        self::assertSame(200, $status, (string) json_encode($body) . self::log());
        self::assertSame(['ok' => true, 'band' => 'trusted'], $body);

        [$status, $body] = self::post('/signup', 'requestId=' . self::TRUSTED, ['Content-Type' => 'application/x-www-form-urlencoded']);
        self::assertSame(403, $status);
        self::assertSame('replayed', $body['reason']);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function refusals(): iterable
    {
        yield 'dangerous band' => ['22222222-2222-4222-8222-222222222222', 'blocked_band', null];
        yield 'automation flag' => ['33333333-3333-4333-8333-333333333333', 'blocked_flag', 'browser_automation'];
        yield 'rate-limit marker' => ['44444444-4444-4444-8444-444444444444', 'rate_limited', null];
        yield 'stale identification' => ['55555555-5555-4555-8555-555555555555', 'stale', null];
    }

    #[DataProvider('refusals')]
    public function testRefusesRiskySignups(string $requestId, string $reason, ?string $flag): void
    {
        [$status, $body] = self::post('/signup', json_encode(['requestId' => $requestId], \JSON_THROW_ON_ERROR));

        self::assertSame(403, $status, (string) json_encode($body) . self::log());
        self::assertFalse($body['ok']);
        self::assertSame($reason, $body['reason']);
        self::assertSame($flag, $body['flag']);
    }

    public function testRejectsABadRequestId(): void
    {
        self::assertSame(400, self::post('/signup', '{"requestId":"not-a-uuid"}')[0]);
        self::assertSame(400, self::post('/signup', '{}')[0]);
        self::assertSame(400, self::post('/signup', 'not json')[0]);
    }

    public function testAcceptsSignedWebhooksOnceAndLogsThem(): void
    {
        $body = Fixtures::raw('webhook-identification-scored.raw.txt');
        $headers = ['Content-Type' => 'application/json', 'X-Shield-Signature' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)];

        self::assertSame([200, ['received' => true]], \array_slice(self::post('/webhooks/shieldlabs', $body, $headers), 0, 2));
        self::assertSame([200, ['received' => true]], \array_slice(self::post('/webhooks/shieldlabs', $body, $headers), 0, 2));

        $log = self::log();
        self::assertStringContainsString('identification.scored request_id=a5b7c9d1-e3f5-4a7b-9c1d-3e5f7a9b1c3d risk_score=80 band=dangerous', $log);
        self::assertStringContainsString('Duplicate delivery ignored for request_id=a5b7c9d1-e3f5-4a7b-9c1d-3e5f7a9b1c3d', $log);
    }

    public function testAcknowledgesTheVerifyPing(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');
        $headers = ['X-Shield-Signature' => 'sha256=ea2685733d254f7028fb031c4214583b0650de01e6c8c93131236024edd9fdd8'];

        self::assertSame([200, ['received' => true]], \array_slice(self::post('/webhooks/shieldlabs', $body, $headers), 0, 2));
        self::assertStringContainsString('webhook.ping received', self::log());
    }

    public function testRejectsUnsignedOrTamperedWebhooks(): void
    {
        $body = Fixtures::raw('webhook-ping.raw.txt');

        self::assertSame(401, self::post('/webhooks/shieldlabs', $body)[0]);
        self::assertSame(401, self::post('/webhooks/shieldlabs', $body . ' ', ['X-Shield-Signature' => 'sha256=ea2685733d254f7028fb031c4214583b0650de01e6c8c93131236024edd9fdd8'])[0]);
    }

    public function testRejectsASignedBodyThatIsNotAnEvent(): void
    {
        $body = '[1,2,3]';

        self::assertSame(400, self::post('/webhooks/shieldlabs', $body, ['X-Shield-Signature' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)])[0]);
    }

    public function testAnswersNotFoundForOtherRoutes(): void
    {
        self::assertSame(404, self::post('/elsewhere', '{}')[0]);
    }

    /**
     * Started as documented (placeholder key, errors displayed), the app logs the SDK's
     * key format warning to the server log and every response keeps its status code and
     * its exact JSON body.
     */
    public function testKeepsTheKeyWarningOutOfResponsesWhenRunAsDocumented(): void
    {
        $cases = [
            [self::TRUSTED, 200, ['ok' => true, 'band' => 'trusted']],
            [self::TRUSTED, 403, ['ok' => false, 'reason' => 'replayed', 'band' => 'trusted', 'flag' => null]],
            ['22222222-2222-4222-8222-222222222222', 403, ['ok' => false, 'reason' => 'blocked_band', 'band' => 'dangerous', 'flag' => null]],
            ['44444444-4444-4444-8444-444444444444', 403, ['ok' => false, 'reason' => 'rate_limited', 'band' => 'rate_limited', 'flag' => null]],
            ['not-a-uuid', 400, ['ok' => false, 'error' => 'requestId must be a UUID']],
        ];
        foreach ($cases as [$requestId, $status, $expected]) {
            [$actualStatus, , $raw] = self::post('/signup', json_encode(['requestId' => $requestId], \JSON_THROW_ON_ERROR), app: self::$documentedApp);

            self::assertSame($status, $actualStatus, $raw);
            self::assertSame(json_encode($expected, \JSON_THROW_ON_ERROR) . "\n", $raw);
        }

        $body = Fixtures::raw('webhook-ping.raw.txt');
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'whsec_your_signing_secret');
        self::assertSame([200, ['received' => true], "{\"received\":true}\n"], self::post('/webhooks/shieldlabs', $body, ['X-Shield-Signature' => $signature], self::$documentedApp));

        \assert(self::$documentedApp !== null);
        self::assertStringContainsString('shieldlabs-php: the API key does not look like a Private API Key', self::$documentedApp->log());
    }
}
