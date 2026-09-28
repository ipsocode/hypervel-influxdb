<?php

declare(strict_types=1);

// Imported from ipsocode/hypervel-packages github/php-cs-fixer-rules.php. Edit it there.

/*
|--------------------------------------------------------------------------
| Coding standard
|--------------------------------------------------------------------------
|
| The rules of the framework's own config,
| `vendor/hypervel/components/.php-cs-fixer.php` on the 0.4 branch, verbatim,
| so a finding here is a finding there. `scripts/sync-github.sh --check` in
| hypervel-packages compares the two. The package's .php-cs-fixer.dist.php
| loads this and keeps its own finder.
|
*/

return [
    '@PSR2' => true,
    '@Symfony' => true,
    '@DoctrineAnnotation' => true,
    '@PhpCsFixer' => true,
    'php_unit_internal_class' => false,
    'php_unit_test_class_requires_covers' => false,
    'phpdoc_no_alias_tag' => false,
    'array_syntax' => [
        'syntax' => 'short',
    ],
    'list_syntax' => [
        'syntax' => 'short',
    ],
    'concat_space' => [
        'spacing' => 'one',
    ],
    'global_namespace_import' => [
        'import_classes' => true,
        'import_constants' => true,
        'import_functions' => null,
    ],
    'blank_line_before_statement' => [
        'statements' => [
            'declare',
        ],
    ],
    'general_phpdoc_annotation_remove' => [
        'annotations' => [
            'author',
        ],
    ],
    'ordered_imports' => [
        'imports_order' => [
            'class', 'function', 'const',
        ],
        'sort_algorithm' => 'alpha',
    ],
    'single_line_comment_style' => [
        'comment_types' => [],
    ],
    'yoda_style' => [
        'always_move_variable' => false,
        'equal' => false,
        'identical' => false,
    ],
    'phpdoc_align' => [
        'align' => 'left',
    ],
    'multiline_whitespace_before_semicolons' => [
        'strategy' => 'no_multi_line',
    ],
    'constant_case' => [
        'case' => 'lower',
    ],
    'class_attributes_separation' => true,
    'combine_consecutive_unsets' => true,
    'declare_strict_types' => true,
    'linebreak_after_opening_tag' => true,
    'lowercase_static_reference' => true,
    'no_useless_else' => true,
    'no_unused_imports' => true,
    'not_operator_with_successor_space' => true,
    'not_operator_with_space' => false,
    'ordered_class_elements' => [
        'order' => [
            'use_trait',
        ],
    ],
    'phpdoc_to_comment' => [
        'ignored_tags' => ['var'],
    ],
    'return_assignment' => [
        'skip_named_var_tags' => true,
    ],
    'php_unit_method_casing' => [
        'case' => 'camel_case',
    ],
    'php_unit_strict' => false,
    'phpdoc_separation' => false,
    'single_quote' => true,
    'standardize_not_equals' => true,
    'multiline_comment_opening_closing' => true,
    'fully_qualified_strict_types' => false,
    // Explicit nullable declarations avoid PHP 8.4 deprecations.
    'nullable_type_declaration_for_default_null_value' => true,
    'new_with_parentheses' => [
        'named_class' => false,
        'anonymous_class' => false,
    ],
    'single_line_empty_body' => false,
    'ordered_types' => [
        'null_adjustment' => 'always_last',
        'sort_algorithm' => 'none',
    ],
];
