<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->notName('*.blade.php'); // optional: exclude templates

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'no_trailing_whitespace' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
