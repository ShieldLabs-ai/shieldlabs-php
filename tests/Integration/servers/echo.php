<?php

declare(strict_types=1);

// Router for `php -S` used by the CurlClient integration tests.

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) parse_url($uri, \PHP_URL_PATH);
parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (is_string($key) && str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

if ($path === '/echo') {
    header('Content-Type: application/json');
    header('X-Multi: one', false);
    header('X-Multi: two', false);
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $uri,
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ]);
} elseif (preg_match('#^/status/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    header('Content-Type: text/plain');
    echo 'status ' . $match[1];
} elseif ($path === '/slow') {
    usleep((int) ($query['ms'] ?? 1000) * 1000);
    echo 'late';
} elseif (str_starts_with($path, '/slowapi/')) {
    usleep(1_500_000);
    header('Content-Type: application/json');
    echo '{"data":[],"total":0}';
} elseif (str_starts_with($uri, '/capture/')) {
    // Answers 400 with the raw request target, so a test sees exactly what was sent.
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $uri]);
} elseif ($path === '/redirect') {
    header('Location: http://127.0.0.1:1/elsewhere', true, 302);
} elseif (str_starts_with($path, '/api/v1/history/')) {
    if (($headers['authorization'] ?? '') !== 'Bearer sec_abcd1234-efgh5678-ijkl9012') {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "{\"error\":\"invalid api key\"}\n";
    } else {
        header('Content-Type: application/json');
        readfile(dirname(__DIR__, 2) . '/data/history-page.json');
    }
} else {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 page not found';
}
