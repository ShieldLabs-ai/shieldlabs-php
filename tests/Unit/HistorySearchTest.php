<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Exception\ApiException;
use ShieldLabs\Exception\ValidationException;
use ShieldLabs\LookupType;
use ShieldLabs\ShieldLabs;
use ShieldLabs\Tests\Support\Clients;
use ShieldLabs\Tests\Support\ErrorLog;
use ShieldLabs\Tests\Support\Fixtures;
use ShieldLabs\Tests\Support\MockHttpClient;

final class HistorySearchTest extends TestCase
{
    public function testSendsTheDocumentedRequest(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, Fixtures::json('history-page.json')));

        $page = Clients::history($http)->history->search(LookupType::DeviceId, 'AC7C303D-971B-41D1-8E25-CD5B46B46AED', ['limit' => 50]);

        self::assertSame(37, $page->total);
        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://account.shieldlabs.ai/api/v1/history/device_id/ac7c303d-971b-41d1-8e25-cd5b46b46aed?limit=50&offset=0',
            (string) $request->getUri(),
        );
        self::assertSame('Bearer ' . Clients::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertMatchesRegularExpression('#^shieldlabs-php/1\.0\.1 \(PHP \d+\.\d+\.\d+.*; \w+\)$#', $request->getHeaderLine('User-Agent'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testUsesTheDefaultLimitAndOffset(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        Clients::history($http)->history->search('ip', '203.0.113.24');

        self::assertSame('https://account.shieldlabs.ai/api/v1/history/ip/203.0.113.24?limit=20&offset=0', (string) $http->lastRequest()->getUri());
    }

    public function testEscapesTheUserHidAsOnePathSegment(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        Clients::history($http)->history->search(LookupType::UserHid, 'Team A:42 ✓?', ['limit' => 100, 'offset' => 200]);

        self::assertSame(
            'https://account.shieldlabs.ai/api/v1/history/user_hid/Team%20A:42%20%E2%9C%93%3F?limit=100&offset=200',
            (string) $http->lastRequest()->getUri(),
        );
    }

    /**
     * The History API decodes the value only in canonical path form: A-Z a-z 0-9 - . _ ~
     * and $ & + , : ; = @ unescaped, every other byte as uppercase %XX.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function canonicalUserHids(): iterable
    {
        yield 'email address' => ['jane.doe+test@example.com', 'jane.doe+test@example.com'];
        yield 'base64 with padding' => ['dXNlcjE+Lw==', 'dXNlcjE+Lw=='];
        yield 'reserved characters kept' => ['a$b&c+d,e:f;g=h@i', 'a$b&c+d,e:f;g=h@i'];
        yield 'unreserved marks kept' => ['A-z_0.9~', 'A-z_0.9~'];
        yield 'sub-delimiters escaped' => ["x!'()*y", 'x%21%27%28%29%2Ay'];
        yield 'space and question mark' => ['a b?c', 'a%20b%3Fc'];
        yield 'hash, percent and backslash' => ['#50%\\x', '%2350%25%5Cx'];
        yield 'escaped-looking text stays literal' => ['%2F%40', '%252F%2540'];
        yield 'UTF-8 bytes in uppercase hex' => ['Zoë ✓', 'Zo%C3%AB%20%E2%9C%93'];
        yield 'three dots' => ['...', '...'];
        yield 'leading dot' => ['.hidden', '.hidden'];
        yield 'hex User HID' => [str_repeat('0a', 32), str_repeat('0a', 32)];
    }

    #[DataProvider('canonicalUserHids')]
    public function testSendsUserHidsInCanonicalPathForm(string $userHid, string $segment): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $client = Clients::history($http);

        $client->history->search(LookupType::UserHid, $userHid);
        iterator_to_array($client->history->iterate('user_hid', $userHid));

        self::assertCount(2, $http->requests);
        foreach ($http->requests as $request) {
            self::assertSame('/api/v1/history/user_hid/' . $segment, $request->getUri()->getPath());
        }
    }

    /**
     * Every byte value, checked against the rule itself: A-Z a-z 0-9 - . _ ~ and
     * $ & + , : ; = @ are sent as they are, every other byte as %XX in uppercase.
     */
    public function testEscapesEveryByteInCanonicalPathForm(): void
    {
        $plain = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~$&+,:;=@';
        $userHid = '';
        $expected = '';
        for ($byte = 0; $byte < 256; ++$byte) {
            $char = \chr($byte);
            if ($char === '/') {
                continue; // refused before anything is sent
            }
            $userHid .= $char;
            $expected .= str_contains($plain, $char) ? $char : \sprintf('%%%02X', $byte);
        }
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        Clients::history($http)->history->search(LookupType::UserHid, $userHid);

        self::assertSame('/api/v1/history/user_hid/' . $expected, $http->lastRequest()->getUri()->getPath());
        self::assertSame(\strlen($plain) + 3 * (255 - \strlen($plain)), \strlen($expected));
    }

    public function testKeepsSentinelUserHidsAsStrings(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        Clients::history($http)->history->search(LookupType::UserHid, 'anonymous');

        self::assertStringContainsString('/user_hid/anonymous?', (string) $http->lastRequest()->getUri());
    }

    public function testAcceptsTheNilUuidAndAnyVersion(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $client = Clients::history($http);

        $client->history->search(LookupType::VisitorId, '00000000-0000-0000-0000-000000000000');
        $client->history->search(LookupType::SessionId, 'bde0e249-20d8-5544-838c-ed9a0b6d7a36');
        $client->history->search(LookupType::CookieId, '4449bb58-590c-144c-ae1f-d1ddc768dbdd');

        self::assertCount(3, $http->requests);
    }

    /**
     * @return iterable<string, array{LookupType|string, string, array<string, mixed>, string}>
     */
    public static function invalidLookups(): iterable
    {
        yield 'unknown type' => ['auto', '203.0.113.24', [], 'Unknown lookup type "auto"'];
        yield 'empty type' => ['', '203.0.113.24', [], 'Unknown lookup type'];
        yield 'uppercase type' => ['DEVICE_ID', 'ac7c303d-971b-41d1-8e25-cd5b46b46aed', [], 'Unknown lookup type'];
        yield 'bad UUID' => [LookupType::DeviceId, 'abc', [], 'must be a UUID'];
        yield 'UUID without dashes' => [LookupType::RequestId, 'ac7c303d971b41d18e25cd5b46b46aed', [], 'must be a UUID'];
        yield 'UUID in braces' => [LookupType::VisitorId, '{ac7c303d-971b-41d1-8e25-cd5b46b46aed}', [], 'must be a UUID'];
        yield 'UUID with newline' => [LookupType::RequestId, "ac7c303d-971b-41d1-8e25-cd5b46b46aed\n", [], 'must be a UUID'];
        yield 'IPv6' => [LookupType::Ip, '2001:db8::1', [], 'IPv6'];
        yield 'IPv4 out of range' => [LookupType::Ip, '203.0.113.256', [], 'IPv4'];
        yield 'IPv4 with spaces' => [LookupType::Ip, ' 203.0.113.5', [], 'IPv4'];
        yield 'empty user_hid' => [LookupType::UserHid, '', [], 'non-empty'];
        yield 'user_hid with a slash' => [LookupType::UserHid, 'team/42', [], 'cannot contain "/"'];
        yield 'user_hid that is a slash' => ['user_hid', '/', [], 'cannot contain "/"'];
        yield 'user_hid with a trailing slash' => [LookupType::UserHid, 'user-7/', [], 'cannot contain "/"'];
        yield 'user_hid "."' => [LookupType::UserHid, '.', [], 'cannot be "." or ".."'];
        yield 'user_hid ".."' => ['user_hid', '..', [], 'cannot be "." or ".."'];
        yield 'limit 0' => [LookupType::Ip, '203.0.113.24', ['limit' => 0], 'from 1 to 100'];
        yield 'limit 101' => [LookupType::Ip, '203.0.113.24', ['limit' => 101], 'from 1 to 100'];
        yield 'limit as string' => [LookupType::Ip, '203.0.113.24', ['limit' => '50'], 'integer'];
        yield 'negative offset' => [LookupType::Ip, '203.0.113.24', ['offset' => -1], 'greater than or equal to 0'];
        yield 'unknown option' => [LookupType::Ip, '203.0.113.24', ['page' => 2], 'Unknown option "page"'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidLookups')]
    public function testRejectsInvalidLookupsWithoutSendingAnything(LookupType|string $type, string $value, array $options, string $message): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        try {
            /** @phpstan-ignore argument.type */
            Clients::history($http)->history->search($type, $value, $options);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'origin' => ['https://account.shieldlabs.ai', 'https://account.shieldlabs.ai/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'trailing slash' => ['https://account.shieldlabs.ai/', 'https://account.shieldlabs.ai/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'api suffix' => ['https://account.shieldlabs.ai/api', 'https://account.shieldlabs.ai/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'api suffix with slash' => ['https://dev.account.shieldlabs.ai/api/', 'https://dev.account.shieldlabs.ai/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'path prefix' => ['https://proxy.example.com/shieldlabs/api', 'https://proxy.example.com/shieldlabs/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'host named api' => ['https://api', 'https://api/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'local port' => ['http://127.0.0.1:8080', 'http://127.0.0.1:8080/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'other loopback address' => ['http://127.0.0.2/api', 'http://127.0.0.2/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'localhost' => ['http://localhost:8080/', 'http://localhost:8080/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'subdomain of localhost' => ['http://mock.localhost', 'http://mock.localhost/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'IPv6 loopback' => ['http://[::1]:8080/api', 'http://[::1]:8080/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'IPv6 loopback, long form' => ['http://[0:0:0:0:0:0:0:1]', 'http://[0:0:0:0:0:0:0:1]/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
        yield 'uppercase scheme' => ['HTTPS://account.shieldlabs.ai', 'https://account.shieldlabs.ai/api/v1/history/ip/192.0.2.1?limit=20&offset=0'];
    }

    #[DataProvider('baseUrls')]
    public function testNeverBuildsApiApiPaths(string $baseUrl, string $expected): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());

        Clients::history($http, null, ['base_url' => $baseUrl])->history->search(LookupType::Ip, '192.0.2.1');

        self::assertSame($expected, (string) $http->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseUrls(): iterable
    {
        yield 'no scheme' => ['account.shieldlabs.ai'];
        yield 'ftp' => ['ftp://account.shieldlabs.ai'];
        yield 'query' => ['https://account.shieldlabs.ai?x=1'];
        yield 'credentials' => ['https://user:pass@account.shieldlabs.ai'];
        yield 'password only' => ['https://:secret@account.shieldlabs.ai'];
        yield 'fragment' => ['https://account.shieldlabs.ai#x'];
        yield 'empty' => [''];
        yield 'line break inside' => ["https://account.shield\nlabs.ai"];
        yield 'space in the host' => ['https://account shieldlabs.ai'];
        yield 'escaped host' => ['https://account.shieldl%61bs.ai'];
        yield 'not ASCII' => ['https://bücher.example'];
        yield 'plain http to a remote host' => ['http://account.shieldlabs.ai'];
        yield 'plain http to a name that starts like localhost' => ['http://localhost.example.com'];
        yield 'plain http to a name that starts like a loopback address' => ['http://127.0.0.1.example.com'];
        yield 'plain http to another address' => ['http://192.0.2.10:8080'];
    }

    #[DataProvider('invalidBaseUrls')]
    public function testRejectsInvalidBaseUrls(string $baseUrl): void
    {
        $this->expectException(ValidationException::class);
        Clients::history(new MockHttpClient(), null, ['base_url' => $baseUrl]);
    }

    public function testExplainsWhyPlainHttpIsRefused(): void
    {
        foreach (['base_url' => 'http://account.shieldlabs.ai', 'env' => 'http://mock.example.test:8080'] as $source => $url) {
            putenv($source === 'env' ? 'SHIELDLABS_API_BASE_URL=' . $url : 'SHIELDLABS_API_BASE_URL');
            try {
                Clients::history(new MockHttpClient(), null, $source === 'env' ? [] : ['base_url' => $url]);
                self::fail('Expected a ValidationException');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('must be an https URL', $exception->getMessage());
                self::assertStringContainsString('"allow_insecure_http" => true', $exception->getMessage());
            } finally {
                putenv('SHIELDLABS_API_BASE_URL');
            }
        }
    }

    public function testAcceptsPlainHttpToAnotherHostOnlyWhenAskedTo(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $log = ErrorLog::during(static function () use ($http): void {
            $options = ['base_url' => 'http://shieldlabs-mock:8080/api', 'allow_insecure_http' => true];
            Clients::history($http, null, $options)->history->search(LookupType::Ip, '192.0.2.1');
            Clients::history($http, null, $options)->history->search(LookupType::Ip, '192.0.2.1');
        });

        self::assertSame('http://shieldlabs-mock:8080/api/v1/history/ip/192.0.2.1?limit=20&offset=0', (string) $http->lastRequest()->getUri());
        self::assertCount(1, $log->warnings(), 'warned once');
        self::assertStringContainsString('uses plain http', $log->warnings()[0]);
        self::assertStringNotContainsString(Clients::API_KEY, $log->contents);
    }

    public function testHttpsNeedsNoOptIn(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
        $log = ErrorLog::during(static function () use ($http): void {
            Clients::history($http, null, ['base_url' => 'https://proxy.example.com', 'allow_insecure_http' => true])->history->search(LookupType::Ip, '192.0.2.1');
            Clients::history($http, null, ['base_url' => 'http://127.0.0.1:9'])->history->search(LookupType::Ip, '192.0.2.1');
        });

        self::assertSame('', $log->contents);
        self::assertCount(2, $http->requests);
    }

    public function testReadsTheBaseUrlFromTheEnvironment(): void
    {
        putenv('SHIELDLABS_API_BASE_URL=https://dev.account.shieldlabs.ai/api');
        try {
            $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
            Clients::history($http)->history->search(LookupType::Ip, '192.0.2.1');
            self::assertStringStartsWith('https://dev.account.shieldlabs.ai/api/v1/history/', (string) $http->lastRequest()->getUri());
        } finally {
            putenv('SHIELDLABS_API_BASE_URL');
        }
    }

    public function testPrefersTheOptionOverTheEnvironment(): void
    {
        putenv('SHIELDLABS_API_BASE_URL=https://dev.account.shieldlabs.ai');
        try {
            $http = (new MockHttpClient())->always(MockHttpClient::emptyPage());
            Clients::history($http, null, ['base_url' => 'http://127.0.0.1:9'])->history->search(LookupType::Ip, '192.0.2.1');
            self::assertStringStartsWith('http://127.0.0.1:9/api/v1/history/', (string) $http->lastRequest()->getUri());
        } finally {
            putenv('SHIELDLABS_API_BASE_URL');
        }
    }

    public function testRejectsAnUnexpectedSuccessBody(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, 'oops'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('unexpected response body');
        Clients::history($http)->history->search(LookupType::Ip, '192.0.2.1');
    }

    public function testRejectsASuccessBodyThatIsNotJson(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::text(200, '<html>maintenance</html>', 'text/html'));

        try {
            Clients::history($http)->history->search(LookupType::Ip, '192.0.2.1');
            self::fail('Expected an ApiException');
        } catch (ApiException $exception) {
            self::assertSame(200, $exception->getStatusCode());
            self::assertStringContainsString('not valid JSON', $exception->getMessage());
        }
        self::assertCount(1, $http->requests);
    }

    public function testToleratesOddPageShapes(): void
    {
        $http = (new MockHttpClient())->queue(
            MockHttpClient::json(200, ['data' => [['request_id' => '3b241101-e2bb-4255-8caf-4136c566a962'], 'junk', 7], 'total' => 2.0]),
            MockHttpClient::json(200, ['data' => 'nope']),
            MockHttpClient::json(200, ['total' => 'x']),
        );
        $client = Clients::history($http);

        $page = $client->history->search(LookupType::Ip, '192.0.2.1');
        self::assertCount(1, $page->data);
        self::assertSame(2, $page->total);
        self::assertSame('3b241101-e2bb-4255-8caf-4136c566a962', $page->data[0]->request_id);

        self::assertSame(0, $client->history->search(LookupType::Ip, '192.0.2.1')->total);
        self::assertSame(0, $client->history->search(LookupType::Ip, '192.0.2.1')->total);
    }

    public function testIteratesAPage(): void
    {
        $http = (new MockHttpClient())->always(MockHttpClient::json(200, Fixtures::json('history-page.json')));

        $page = Clients::history($http)->history->search(LookupType::Ip, '192.0.2.1');
        $ids = [];
        foreach ($page as $identification) {
            $ids[] = $identification->request_id;
        }

        self::assertCount(5, $ids);
        self::assertSame('02f1d973-84db-4156-a7f7-e799e6bf389b', $ids[0]);
    }

    public function testVersionConstantMatchesTheChangelog(): void
    {
        $changelog = (string) file_get_contents(\dirname(__DIR__, 2) . '/CHANGELOG.md');

        self::assertStringContainsString('## [' . ShieldLabs::VERSION . '] - 2026-10-05', $changelog);
    }
}
