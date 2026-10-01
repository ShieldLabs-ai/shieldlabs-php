<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'native_constant_invocation' => ['scope' => 'namespaced', 'strict' => true],
        'no_empty_phpdoc' => true,
        'phpdoc_trim' => true,
    ])
    ->setFinder($finder);
