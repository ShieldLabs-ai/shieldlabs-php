<?php

declare(strict_types=1);

// Run with only the fresh consumer's runtime autoloader, never this checkout's.
require $argv[1];

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ShieldLabs\ShieldLabs;
use ShieldLabs\ShieldLabsManagement;
use ShieldLabs\Webhook;

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Packed consumer assertion failed: ' . json_encode([$expected, $actual]));
    }
}

$client = new class implements ClientInterface {
    public ?RequestInterface $last = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;
        if ($request->getUri()->getPath() === '/v1/profile') {
            return new Response(200, [], json_encode(['Domain' => 'example.com', 'Weight' => -2, 'PublicKey' => '***1234', 'Secret' => '***5678', 'CreatedAt' => null, 'future' => 'kept'], JSON_THROW_ON_ERROR));
        }

        return new Response(200, [], json_encode(['data' => [[
            'request_id' => '00000000-0000-0000-0000-000000000001',
            'score' => 999,
            'connection_type' => 'future-network',
            'score_details' => '[{"Description":"future signal","Value":-30},{"Description":"future signal","Value":-30}]',
            'is_vpn' => true,
            'future' => ['kept' => true],
        ]], 'total' => 1], JSON_THROW_ON_ERROR));
    }
};
$sdk = new ShieldLabs(['api_key' => 'sec_00000000-00000000-00000000', 'http_client' => $client]);
$page = $sdk->history->search('user_hid', 'anonymous', ['limit' => 2, 'offset' => 3]);
expectSame('/api/v1/history/user_hid/anonymous', $client->last->getUri()->getPath());
expectSame('limit=2&offset=3', $client->last->getUri()->getQuery());
expectSame(1, $page->total);
expectSame(999, $page->data[0]->risk_score);
expectSame('future-network', $page->data[0]->connection_type);
expectSame([-30, -30], array_map(static fn($signal) => $signal->weight, $page->data[0]->signals));
expectSame(['kept' => true], $page->data[0]->raw['future']);
expectSame(true, $page->data[0]->detection_flags->vpn);

$management = new ShieldLabsManagement(['secret_key' => 'fixture_secret', 'domain' => 'example.com', 'http_client' => $client]);
$profile = $management->getProfile();
expectSame('example.com', $client->last->getHeaderLine('X-Shield-Domain'));
expectSame(-2, $profile->remaining_identifications);
expectSame('kept', $profile->raw['future']);

$payload = json_encode(['event_type' => 'identification.scored', 'schema_version' => 'future', 'data' => [
    'risk_score' => 999, 'user_hid' => null, 'connection_type' => 'future-network',
    'signals' => [['name' => 'future', 'weight' => -30], ['name' => 'future', 'weight' => -30]],
    'detection_flags' => ['vpn' => true], 'future' => 'kept',
]], JSON_THROW_ON_ERROR);
$secret = 'whsec_fixture_package';
$event = Webhook::constructEvent($payload, 'sha256=' . hash_hmac('sha256', $payload, $secret), $secret);
expectSame(999, $event->data->risk_score);
expectSame([-30, -30], array_map(static fn($signal) => $signal->weight, $event->data->signals));
expectSame(null, $event->data->user_hid);
expectSame('kept', $event->data->raw['future']);
expectSame(true, $event->data->detection_flags->vpn);
echo "Packed runtime-only consumer: History, profile, signed webhook PASS\n";
