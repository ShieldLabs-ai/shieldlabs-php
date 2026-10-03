<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scratch = $root . '/build/package-' . bin2hex(random_bytes(8));
mkdir($scratch . '/consumer', 0777, true);

function runPackageCommand(array $command, string $directory): void
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $directory);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Package check command failed: ' . implode(' ', $command));
    }
}

runPackageCommand(['composer', 'archive', '--no-interaction', '--format=tar', '--dir=' . $scratch, '--file=package'], $root);
$archive = new PharData($scratch . '/package.tar');
foreach (new RecursiveIteratorIterator($archive) as $entry) {
    $relative = substr($entry->getPathname(), strlen('phar://' . $scratch . '/package.tar/'));
    if (preg_match('~^(vendor|build|\.git|\.phpstan.cache)(/|$)~', $relative)) {
        throw new RuntimeException('The archive contains development state.');
    }
}
$metadata = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$package = array_intersect_key($metadata, array_flip(['name', 'description', 'type', 'license', 'require', 'autoload']));
$package['version'] = '1.0.0';
$package['dist'] = ['type' => 'tar', 'url' => 'file://' . $scratch . '/package.tar'];
file_put_contents($scratch . '/consumer/composer.json', json_encode([
    'name' => 'shieldlabs/package-consumer',
    'repositories' => [['type' => 'package', 'package' => $package]],
    'require' => [$package['name'] => '1.0.0'],
    'config' => ['allow-plugins' => ['php-http/discovery' => false]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
runPackageCommand(['composer', 'install', '--no-dev', '--no-scripts', '--no-interaction', '--no-progress'], $scratch . '/consumer');
if (is_dir($scratch . '/consumer/vendor/phpstan') || is_dir($scratch . '/consumer/vendor/symfony/yaml')) {
    throw new RuntimeException('The runtime consumer unexpectedly installed build dependencies.');
}
runPackageCommand([PHP_BINARY, $root . '/tests/Package/consumer.php', $scratch . '/consumer/vendor/autoload.php'], $scratch . '/consumer');
echo 'Fresh archive consumer passed. Artifacts: ' . basename($scratch) . "\n";
