<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    // The project targets PHP 8.3; contributors may be running a newer runtime.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
        ],
    ])
    ->setFinder($finder);
