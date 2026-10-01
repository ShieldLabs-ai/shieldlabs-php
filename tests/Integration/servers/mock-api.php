<?php

declare(strict_types=1);

// Router for `php -S`: a tiny stand-in for the History API used by the example tests.

header('Content-Type: application/json');
// The test key, and the placeholder key used in the README and the example instructions.
$keys = ['sec_abcd1234-efgh5678-ijkl9012', 'sec_your_private_key'];
if (!in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', array_map(static fn(string $key): string => 'Bearer ' . $key, $keys), true)) {
    http_response_code(401);
    echo "{\"error\":\"invalid api key\"}\n";

    return;
}
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
if (preg_match('#^/api/v1/history/request_id/([0-9a-f-]{36})$#', $path, $match) !== 1) {
    http_response_code(404);
    echo '"not found"';

    return;
}

$page = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/history-page.json'), true);
$template = $page['data'][1];
$variants = [
    '11111111-1111-4111-8111-111111111111' => ['score' => 10],
    '22222222-2222-4222-8222-222222222222' => ['score' => 80],
    '33333333-3333-4333-8333-333333333333' => ['score' => 45, 'is_browser_automation' => true],
    '44444444-4444-4444-8444-444444444444' => ['score' => 999, 'device_id' => '00000000-0000-0000-0000-000000000000'],
    '55555555-5555-4555-8555-555555555555' => ['score' => 15, 'created_at' => '2020-01-01 00:00:00.000'],
];
$requestId = $match[1];
if (!isset($variants[$requestId])) {
    echo json_encode(['data' => [], 'total' => 0]);

    return;
}
$row = array_merge($template, ['created_at' => gmdate('Y-m-d H:i:s') . '.000'], $variants[$requestId], ['request_id' => $requestId]);
echo json_encode(['data' => [$row], 'total' => 1]);
