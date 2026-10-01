<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Integration;

use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Http\CurlClient;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Tests\Support\PhpServer;

/**
 * Runs the Quick start scripts from README.md exactly as written, the way a reader
 * would: each one saved next to a vendor/ folder and served by `php -S`, against a
 * local stand-in for the History API, with PHP displaying errors in responses.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_your_signing_secret';

    private static string $project = '';
    private static ?PhpServer $api = null;

    /** @var array<string, PhpServer> */
    private static array $servers = [];

    public static function setUpBeforeClass(): void
    {
        self::$project = sys_get_temp_dir() . '/shieldlabs-readme-' . bin2hex(random_bytes(6));
        mkdir(self::$project . '/vendor', 0o755, true);
        mkdir(self::$project . '/tmp');
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents(self::$project . '/vendor/autoload.php', "<?php\n\nreturn require " . var_export($autoload, true) . ";\n");
        self::$api = new PhpServer(__DIR__ . '/servers/mock-api.php');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            $server->stop();
        }
        self::$servers = [];
        self::$api?->stop();
        self::$api = null;
        self::remove(self::$project);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private static function phpBlocks(): array
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        preg_match_all('/^```php\n(.*?)^```$/ms', $readme, $matches);

        return $matches[1];
    }

    /**
     * The one complete script in README.md (it starts with the PHP open tag and loads
     * the autoloader) that contains $marker.
     */
    private static function script(string $marker): string
    {
        $scripts = array_values(array_filter(
            self::phpBlocks(),
            static fn(string $block): bool => str_starts_with($block, "<?php\n")
                && str_contains($block, "require __DIR__ . '/vendor/autoload.php';")
                && str_contains($block, $marker),
        ));
        self::assertCount(1, $scripts, 'README.md must contain one complete script with ' . $marker);

        return $scripts[0];
    }

    private static function serve(string $name, string $marker): PhpServer
    {
        if (!isset(self::$servers[$name])) {
            \assert(self::$api !== null);
            $file = self::$project . '/' . $name . '.php';
            file_put_contents($file, self::script($marker));
            self::$servers[$name] = new PhpServer(
                $file,
                ['SHIELDLABS_API_BASE_URL' => self::$api->url],
                ['display_errors' => '1', 'error_reporting' => '-1', 'sys_temp_dir' => self::$project . '/tmp'],
            );
        }

        return self::$servers[$name];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string}
     */
    private static function post(PhpServer $server, string $body, array $headers): array
    {
        $response = (new CurlClient(20.0))->sendRequest(new Request('POST', $server->url . '/', $headers, $body));

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    /**
     * Runs `php -l` on a file (no shell involved).
     *
     * @return array{int, string} exit code and output
     */
    private static function lint(string $file): array
    {
        $process = proc_open([\PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new \RuntimeException('Could not run php -l');
        }
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testEveryPhpBlockIsValidPhp(): void
    {
        $blocks = self::phpBlocks();
        self::assertGreaterThanOrEqual(5, \count($blocks));

        foreach ($blocks as $index => $block) {
            $file = self::$project . '/block-' . $index . '.php';
            file_put_contents($file, str_starts_with($block, '<?php') ? $block : "<?php\n\n" . $block);
            [$code, $output] = self::lint($file);

            self::assertSame(0, $code, "README.md php block #{$index} does not parse:\n{$block}\n{$output}");
        }
    }

    public function testTheSignupScriptRunsAsWritten(): void
    {
        $server = self::serve('signup', 'identifications->get(');
        $json = ['Content-Type' => 'application/json'];
        $form = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $refused = static fn(string $reason): string => '{"error":"signup refused","reason":"' . $reason . '"}';
        $badRequest = '{"error":"requestId must be a UUID"}';

        self::assertSame([200, '{"ok":true}'], self::post($server, '{"requestId":"11111111-1111-4111-8111-111111111111"}', $json), $server->log());
        self::assertSame([403, $refused('replayed')], self::post($server, 'requestId=11111111-1111-4111-8111-111111111111', $form));
        self::assertSame([403, $refused('blocked_band')], self::post($server, 'requestId=22222222-2222-4222-8222-222222222222', $form));
        self::assertSame([403, $refused('blocked_flag')], self::post($server, '{"requestId":"33333333-3333-4333-8333-333333333333"}', $json));
        self::assertSame([403, $refused('rate_limited')], self::post($server, '{"requestId":"44444444-4444-4444-8444-444444444444"}', $json));
        self::assertSame([403, $refused('stale')], self::post($server, 'requestId=55555555-5555-4555-8555-555555555555', $form));
        self::assertSame([400, $badRequest], self::post($server, '{"requestId":"not-a-uuid"}', $json));
        self::assertSame([400, $badRequest], self::post($server, 'requestId[]=11111111-1111-4111-8111-111111111111', $form));
        self::assertSame([400, $badRequest], self::post($server, '', $form));

        self::assertStringContainsString('shieldlabs-php: the API key does not look like a Private API Key', $server->log());
    }

    public function testTheWebhookScriptRunsAsWritten(): void
    {
        $server = self::serve('webhook', 'Webhook::constructEvent(');
        $scored = Fixtures::raw('webhook-identification-scored.raw.txt');
        $ping = Fixtures::raw('webhook-ping.raw.txt');
        $sign = static fn(string $payload): array => ['Content-Type' => 'application/json', 'X-Shield-Signature' => 'sha256=' . hash_hmac('sha256', $payload, self::WEBHOOK_SECRET)];

        self::assertSame([200, ''], self::post($server, $scored, $sign($scored)), $server->log());
        self::assertSame([200, ''], self::post($server, $ping, $sign($ping)));
        self::assertSame([401, ''], self::post($server, $scored, ['Content-Type' => 'application/json']));
        self::assertSame([401, ''], self::post($server, $scored . ' ', $sign($scored)));
        self::assertSame([400, ''], self::post($server, '[1,2,3]', $sign('[1,2,3]')));
    }
}
