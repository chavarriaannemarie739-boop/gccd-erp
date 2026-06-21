<?php
/**
 * 高齿传动 ERP — PHP CS Fixer 配置
 */
$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/gccd_server/app',
        __DIR__ . '/gccd_server/config',
        __DIR__ . '/gccd_server/route',
    ])
    ->exclude(['vendor', 'bce-php-sdk', 'esdk-obs-php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'new_with_braces' => true,
        'no_leading_import_slash' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder);
