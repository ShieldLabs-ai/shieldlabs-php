<?php

declare(strict_types=1);

require __DIR__ . '/generate-wire.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$document = Yaml::parseFile($root . '/resources/shieldlabs-api.yaml');
$scratch = $root . '/build/contract-' . bin2hex(random_bytes(8));
mkdir($scratch, 0777, true);

function copyContractSource(string $source, string $target): void
{
    mkdir($target, 0777, true);
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir()) {
            copyContractSource($entry->getPathname(), $target . '/' . $entry->getFilename());
        } else {
            copy($entry->getPathname(), $target . '/' . $entry->getFilename());
        }
    }
}

function analyseContract(string $root, string $scratch): array
{
    $process = proc_open([
        PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress',
        '--error-format=json', '--memory-limit=256M', '-c', $scratch . '/phpstan.neon',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHPStan.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($stdout, true);
    if (!is_array($result) || ($result['errors'] ?? []) !== []) {
        throw new RuntimeException('PHPStan did not complete: ' . $stdout . $stderr);
    }

    return [$exit, $result];
}

try {
    copyContractSource($root . '/src', $scratch . '/src');
    file_put_contents($scratch . '/phpstan.neon', "parameters:\n    level: max\n    paths:\n        - src\n    tmpDir: cache\n    treatPhpDocTypesAsCertain: false\n    parallel:\n        maximumNumberOfProcesses: 1\n");
    $cases = ['unchanged' => [$document, false, null]];
    foreach (['searchHistory', 'getDomainProfile'] as $operationId) {
        $changed = $document;
        foreach ($changed['paths'] as &$path) {
            if (($path['get']['operationId'] ?? null) === $operationId) {
                $path['post'] = $path['get'];
                unset($path['get']);
            }
        }
        unset($path);
        $cases["$operationId changed to POST"] = [$changed, true, 'generation:Consumed operation changed HTTP method: ' . $operationId];
    }
    foreach ([
        ['HistoryRow', 'score', 'string'],
        ['DomainProfile', 'Weight', 'string'],
        ['IdentificationScoredData', 'risk_score', 'string'],
        ['DetectionFlags', 'vpn', 'string'],
        ['Signal', 'weight', 'string'],
        ['IpInfo', 'country', 'integer'],
        ['ScoreDetail', 'Value', 'string'],
    ] as [$model, $field, $type]) {
        $changed = $document;
        $changed['components']['schemas'][$model]['properties'][$field] = ['type' => $type];
        $cases["$model.$field type"] = [$changed, true, 'argument.type'];
    }
    $changed = $document;
    $changed['components']['schemas']['HistoryRow']['properties']['renamed_request_id'] = $changed['components']['schemas']['HistoryRow']['properties']['request_id'];
    unset($changed['components']['schemas']['HistoryRow']['properties']['request_id']);
    $cases['HistoryRow.request_id rename'] = [$changed, true, 'staticMethod.notFound'];
    $changed = $document;
    $changed['components']['parameters']['HistoryLimit']['schema'] = ['type' => 'string'];
    $cases['History limit type'] = [$changed, true, 'argument.type'];
    $changed = $document;
    $changed['components']['schemas']['HistoryPage']['properties']['total'] = ['type' => 'string'];
    $cases['History page total type'] = [$changed, true, 'argument.type'];
    $changed = $document;
    $changed['components']['parameters']['HistorySearchType']['schema'] = ['type' => 'integer'];
    $cases['History search_type type'] = [$changed, true, 'argument.type'];
    $changed = $document;
    $changed['components']['parameters']['HistorySearchType']['schema']['enum'] = ['ip'];
    $cases['History search_type narrowing'] = [$changed, true, 'argument.type'];
    $changed = $document;
    $changed['components']['parameters']['HistoryOffset']['name'] = 'renamed_offset';
    $cases['History offset rename'] = [$changed, true, 'staticMethod.notFound'];
    $changed = $document;
    $changed['components']['parameters']['ShieldDomain']['schema'] = ['type' => 'integer'];
    $cases['Profile header type'] = [$changed, true, 'argument.type'];
    foreach ([['HistoryLimit', 'header'], ['ShieldDomain', 'query']] as [$parameter, $location]) {
        $changed = $document;
        $changed['components']['parameters'][$parameter]['in'] = $location;
        $cases["$parameter moved to $location"] = [$changed, true, 'generation:Consumed parameter changed location'];
    }
    foreach (['searchHistory', 'getDomainProfile'] as $operationId) {
        foreach (['query', 'header', 'path'] as $location) {
            $changed = $document;
            foreach ($changed['paths'] as &$path) {
                if (($path['get']['operationId'] ?? null) === $operationId) {
                    $path['get']['parameters'][] = ['name' => 'future_required', 'in' => $location, 'required' => true, 'schema' => ['type' => 'string']];
                }
            }
            unset($path);
            $cases["$operationId required $location"] = [$changed, true, 'generation:Unsupported required parameter'];
        }
    }
    $changed = $document;
    foreach ($changed['paths'] as &$path) {
        if (($path['get']['operationId'] ?? null) === 'searchHistory') {
            $path['parameters'][] = ['name' => 'future_inherited', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        }
    }
    unset($path);
    $cases['Inherited required header'] = [$changed, true, 'generation:Unsupported required parameter'];
    foreach (['created_at', 'schema_version'] as $field) {
        $changed = $document;
        $changed['components']['schemas']['WebhookPingEvent']['properties'][$field] = ['type' => 'integer'];
        $cases["Ping $field type"] = [$changed, true, 'generation:Webhook shared envelope differs'];
        $changed = $document;
        $changed['components']['schemas']['WebhookPingEvent']['properties']['renamed_' . $field] = $changed['components']['schemas']['WebhookPingEvent']['properties'][$field];
        unset($changed['components']['schemas']['WebhookPingEvent']['properties'][$field]);
        $cases["Ping $field rename"] = [$changed, true, 'generation:Webhook shared envelope differs'];
    }
    $changed = $document;
    foreach ($changed['paths'] as &$path) {
        if (($path['get']['operationId'] ?? null) === 'searchHistory') {
            $path['get']['parameters'][] = ['name' => 'future_optional', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
        }
    }
    unset($path);
    $cases['Optional query parameter'] = [$changed, false, null];
    $changed = $document;
    $changed['paths']['/v2/profile'] = $changed['paths']['/v1/profile'];
    unset($changed['paths']['/v1/profile']);
    $cases['Profile path follows schema'] = [$changed, false, null];
    $changed = $document;
    $changed['components']['schemas']['HistoryRow']['properties']['future_optional'] = ['type' => 'string'];
    $cases['additive optional field'] = [$changed, false, null];

    foreach ($cases as $name => [$schema, $mustFail, $identifier]) {
        try {
            $generated = generateWire($schema);
        } catch (RuntimeException $exception) {
            if ($mustFail && str_starts_with($identifier ?? '', 'generation:')
                && str_contains($exception->getMessage(), substr($identifier, strlen('generation:')))) {
                echo "PASS $name rejected by generator: " . $exception->getMessage() . "\n";
                continue;
            }
            throw $exception;
        }
        foreach (glob($scratch . '/src/Internal/Wire/Generated/*.php') as $file) {
            unlink($file);
        }
        foreach ($generated as $file => $content) {
            file_put_contents($scratch . '/src/Internal/Wire/Generated/' . $file, $content);
        }
        [$status, $result] = analyseContract($root, $scratch);
        $messages = [];
        foreach ($result['files'] ?? [] as $path => $file) {
            // The supported client, not the generator, must reject this contract.
            if (!str_contains($path, '/Wire/Generated/')) {
                foreach ($file['messages'] as $message) {
                    $messages[] = $message['identifier'] ?? '';
                }
            }
        }
        if (($mustFail && ($status !== 1 || !in_array($identifier, $messages, true))) || (!$mustFail && $status !== 0)) {
            throw new RuntimeException("Unexpected result for $name:\n" . json_encode($result, JSON_PRETTY_PRINT));
        }
        if ($name === 'Profile path follows schema' || $name === 'Optional query parameter') {
            file_put_contents($scratch . '/profile-probe.php', <<<'PHP'
<?php
require $argv[1];
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ShieldLabs\\')) {
        require __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
    }
}, true, true);
$http = new class implements \Psr\Http\Client\ClientInterface {
    public string $path = '';
    public string $query = '';
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        $this->path = $request->getUri()->getPath();
        $this->query = $request->getUri()->getQuery();
        return new \Nyholm\Psr7\Response(200, [], '{}');
    }
};
if ($argv[2] === 'history') {
    $sdk = new \ShieldLabs\ShieldLabs(['api_key' => 'sec_00000000-00000000-00000000', 'http_client' => $http]);
    $sdk->history->search('user_hid', 'anonymous');
    if ($http->query !== 'limit=20&offset=0') { throw new \RuntimeException('An unsupported optional parameter leaked into the request.'); }
} else {
    $sdk = new \ShieldLabs\ShieldLabsManagement(['secret_key' => 'fixture', 'domain' => 'example.com', 'http_client' => $http]);
    $sdk->getProfile();
    if ($http->path !== '/v2/profile') { throw new \RuntimeException('Profile ignored the generated operation path.'); }
}
PHP);
            $process = proc_open([PHP_BINARY, $scratch . '/profile-probe.php', $root . '/vendor/autoload.php', $name === 'Optional query parameter' ? 'history' : 'profile'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $scratch);
            if (!is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('The supported client failed the changed profile route.');
            }
        }
        echo 'PASS ' . $name . ($mustFail ? ' rejected by client type checking' : ' accepted') . "\n";
    }
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($scratch);
}
