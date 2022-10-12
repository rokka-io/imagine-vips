<?php

$finder = PhpCsFixer\Finder::create()
    ->notPath('src/AppBundle/Command/TestCommand.php')
    ->exclude('tests/*/Fixtures')
    ->exclude('var')
    ->in(__DIR__);

$config = new PhpCsFixer\Config();

return $config
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'combine_consecutive_unsets' => true,
        'heredoc_to_nowdoc' => true,
        'no_extra_blank_lines' => ['tokens' => ['break', 'continue', 'extra', 'return', 'throw', 'use', 'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block']],
        'no_unreachable_default_argument_value' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'non_printable_character' => true,
        'ordered_class_elements' => true,
        'ordered_imports' => true,
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_order' => true,
        'random_api_migration' => true,
        'psr_autoloading' => true,
        'strict_param' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'phpdoc_no_empty_return' => false,
        'no_superfluous_phpdoc_tags' => true,
    ])
    ->setFinder(
        $finder
    );
