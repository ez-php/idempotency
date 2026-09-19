<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->exclude(['var', 'vendor']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP83Migration' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_import_per_statement' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_opening_tag' => true,
        'no_extra_blank_lines' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'one',
            ],
        ],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'return_type_declaration' => ['space_before' => 'none'],
        'single_quote' => true,
        'yoda_style' => false,
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder($finder);
