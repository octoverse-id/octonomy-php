<?php

declare(strict_types=1);

// The PHP 7.4 line's style config. Rector's output is not @PER-CS clean (it de-promotes
// constructors and writes switch blocks), so this line is held to @PSR12 instead.
// no_unused_imports drops the `use` lines the downgrade leaves behind, such as the one for
// PHPUnit's DataProvider attribute once it has become an annotation.

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/examples');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
