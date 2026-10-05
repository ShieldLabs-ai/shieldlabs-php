<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

// This generator deliberately does not emit validators or a transport. Raw input
// may contain nulls, missing values or new enum strings accepted by the SDK.
function resolveWire(array $schema, array $document): array
{
    $seen = [];
    while (isset($schema['$ref'])) {
        $ref = $schema['$ref'];
        if (!str_starts_with($ref, '#/') || isset($seen[$ref])) {
            throw new RuntimeException('Only non-cyclic local references are supported: ' . $ref);
        }
        $seen[$ref] = true;
        $target = $document;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $target = $target[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? throw new RuntimeException('Missing reference: ' . $ref);
        }
        $schema = array_replace($target, array_diff_key($schema, ['$ref' => true]));
    }

    return $schema;
}

function wireType(array $schema, array $document): string
{
    $schema = resolveWire($schema, $document);
    $types = (array) ($schema['type'] ?? throw new RuntimeException('A consumed field needs an explicit type.'));
    $php = array_map(static fn(string $type): string => match ($type) {
        'string' => 'string', 'integer' => 'int', 'number' => 'float',
        'boolean' => 'bool', 'object', 'array' => 'array<mixed>', 'null' => 'null',
        default => throw new RuntimeException('Unsupported wire type: ' . $type),
    }, $types);

    return implode('|', array_unique($php));
}

function wireOperation(array $document, string $id, ?string $expectedMethod = null): array
{
    foreach (['paths', 'webhooks'] as $section) {
        foreach ($document[$section] ?? [] as $template => $path) {
            foreach ($path as $method => $operation) {
                if (is_array($operation) && ($operation['operationId'] ?? null) === $id) {
                    if ($expectedMethod !== null && $method !== $expectedMethod) {
                        throw new RuntimeException('Consumed operation changed HTTP method: ' . $id . ' (expected ' . strtoupper($expectedMethod) . ', got ' . strtoupper($method) . ').');
                    }
                    $parameters = [];
                    foreach (array_merge($path['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
                        $resolved = resolveWire($parameter, $document);
                        $parameters[$resolved['in'] . ':' . $resolved['name']] = $resolved;
                    }
                    $operation['parameters'] = array_values($parameters);
                    return $operation + ['x-sdk-path' => $template];
                }
            }
        }
    }
    throw new RuntimeException('Missing operation: ' . $id);
}

function renderWire(string $class, array $properties, array $document): string
{
    ksort($properties);
    $out = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ShieldLabs\\Internal\\Wire\\Generated;\n\nuse ShieldLabs\\Internal\\Wire\\Field;\n\n/** Generated from resources/shieldlabs-api.yaml. Do not edit. @internal */\nfinal class $class\n{\n";
    foreach ($properties as $name => $schema) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/D', $name)) {
            throw new RuntimeException('Unsupported PHP field identifier: ' . $name);
        }
        $type = wireType($schema, $document);
        $out .= "    /** @return Field<$type> */\n    public static function $name(): Field\n    {\n        /** @var Field<$type> \$field */\n        \$field = new Field('$name');\n\n        return \$field;\n    }\n\n";
    }

    return rtrim($out) . "\n}\n";
}

function generateWire(array $document): array
{
    $history = wireOperation($document, 'searchHistory', 'get');
    $profile = wireOperation($document, 'getDomainProfile', 'get');
    $event = wireOperation($document, 'identificationScored');
    $ping = wireOperation($document, 'webhookPing');
    foreach ([[$history, ['search_type' => 'path', 'value' => 'path', 'limit' => 'query', 'offset' => 'query']], [$profile, ['X-Shield-Domain' => 'header']]] as [$operation, $supported]) {
        foreach ($operation['parameters'] as $parameter) {
            if (isset($supported[$parameter['name']]) && $supported[$parameter['name']] !== $parameter['in']) {
                throw new RuntimeException('Consumed parameter changed location: ' . $parameter['name']);
            }
            if (($parameter['required'] ?? false) && !isset($supported[$parameter['name']])) {
                throw new RuntimeException('Unsupported required parameter: ' . $parameter['name']);
            }
        }
    }
    $page = resolveWire($history['responses']['200']['content']['application/json']['schema'], $document);
    $row = resolveWire($page['properties']['data'], $document);
    $historyRow = resolveWire($row['items'], $document);
    $scoreDetails = resolveWire($historyRow['properties']['score_details'], $document);
    $scoreDetailArray = resolveWire($scoreDetails['contentSchema'], $document);
    $envelope = resolveWire($event['requestBody']['content']['application/json']['schema'], $document);
    $pingEnvelope = resolveWire($ping['requestBody']['content']['application/json']['schema'], $document);
    // Parsing uses a shared envelope before discriminating the event. Reject a
    // ping-only rename/type change rather than silently reading scored fields.
    foreach (['event_type', 'schema_version', 'created_at'] as $field) {
        if (!isset($pingEnvelope['properties'][$field], $envelope['properties'][$field])
            || wireType($pingEnvelope['properties'][$field], $document) !== wireType($envelope['properties'][$field], $document)) {
            throw new RuntimeException('Webhook shared envelope differs: ' . $field);
        }
    }
    $data = resolveWire($envelope['properties']['data'], $document);
    $signalArray = resolveWire($data['properties']['signals'], $document);
    $models = [
        'HistoryPage' => $page,
        'HistoryRow' => $historyRow,
        'DomainProfile' => resolveWire($profile['responses']['200']['content']['application/json']['schema'], $document),
        'WebhookEnvelope' => $envelope,
        'ScoredData' => $data,
        'DetectionFlags' => resolveWire($data['properties']['detection_flags'], $document),
        'TrafficSource' => resolveWire($data['properties']['traffic_source'], $document),
        'IpInfo' => resolveWire($data['properties']['public_ip'], $document),
        'LocalIpInfo' => resolveWire($data['properties']['local_ip'], $document),
        'Signal' => resolveWire($signalArray['items'], $document),
        'ScoreDetail' => resolveWire($scoreDetailArray['items'], $document),
    ];
    $out = [];
    foreach ($models as $class => $schema) {
        $out[$class . '.php'] = renderWire($class, $schema['properties'], $document);
    }
    foreach (['HistoryParameters' => $history, 'ProfileParameters' => $profile] as $class => $operation) {
        $properties = [];
        foreach ($operation['parameters'] as $parameter) {
            $parameter = resolveWire($parameter, $document);
            // PHP method identifiers cannot contain header hyphens.
            $method = str_replace('-', '_', $parameter['name']);
            $properties[$method] = $parameter['schema'];
        }
        $rendered = renderWire($class, $properties, $document);
        foreach ($operation['parameters'] as $parameter) {
            $parameter = resolveWire($parameter, $document);
            $rendered = str_replace("new Field('" . str_replace('-', '_', $parameter['name']) . "')", "new Field('" . $parameter['name'] . "')", $rendered);
        }
        if ($class === 'HistoryParameters' || $class === 'ProfileParameters') {
            $arguments = [];
            $replacements = [];
            $docs = [];
            foreach ($operation['parameters'] as $parameter) {
                $parameter = resolveWire($parameter, $document);
                if ($parameter['in'] !== 'path') {
                    continue;
                }
                $name = $parameter['name'];
                $schema = resolveWire($parameter['schema'], $document);
                $type = wireType($schema, $document);
                if (!in_array($type, ['string', 'int'], true)) {
                    throw new RuntimeException('Unsupported path parameter type: ' . $type);
                }
                $arguments[] = "$type \$$name";
                $replacements[] = "'{{$name}}' => (string) \$$name";
                $docType = isset($schema['enum']) ? implode('|', array_map(static fn(string $value): string => var_export($value, true), $schema['enum'])) : $type;
                $docs[] = "     * @param $docType \$$name";
            }
            $path = var_export($operation['x-sdk-path'], true);
            $comment = $docs === [] ? '    /** Operation path. */' : "    /**\n     * Path values must already be validated and escaped.\n" . implode("\n", $docs) . "\n     */";
            $method = "\n$comment\n    public static function path(" . implode(', ', $arguments) . "): string\n    {\n        return strtr($path, [" . implode(', ', $replacements) . "]);\n    }\n";
            $rendered = substr($rendered, 0, -2) . $method . "}\n";
        }
        $out[$class . '.php'] = $rendered;
    }

    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $check = in_array('--check', $argv, true);
    $document = Yaml::parseFile(dirname(__DIR__) . '/resources/shieldlabs-api.yaml');
    $directory = dirname(__DIR__) . '/src/Internal/Wire/Generated';
    $files = generateWire($document);
    if (!$check && !is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    foreach ($files as $name => $content) {
        if ($check) {
            if (!is_file("$directory/$name") || file_get_contents("$directory/$name") !== $content) {
                fwrite(STDERR, "Generated wire types are stale: $name. Run composer generate:wire.\n");
                exit(1);
            }
        } else {
            file_put_contents("$directory/$name", $content);
        }
    }
    $extra = array_diff(array_map('basename', glob("$directory/*.php") ?: []), array_keys($files));
    if ($extra !== []) {
        throw new RuntimeException('Unexpected generated files: ' . implode(', ', $extra));
    }
    echo $check ? "Generated wire types are current.\n" : "Generated wire types written.\n";
}
