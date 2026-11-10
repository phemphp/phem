<?php

declare(strict_types=1);

$header = <<<'EOF'
    This file is part of the Phem package.

    (c) Yonel Ceruto <open@yceruto.dev>

    For the full copyright and license information, please view the LICENSE
    file that was distributed with this source code.
    EOF;

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/config', __DIR__.'/tests'])
    // The PHP that phemc, Twig and Symfony generate in the var/ of the test apps.
    ->exclude(['Fixtures/app/var', 'Fixtures/Package/var'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // Phem reads the object of a closure (ReflectionFunction::getClosureThis()), and binds
        // closures to it: a static closure has no object.
        'static_lambda' => false,
        'header_comment' => ['header' => $header, 'location' => 'after_open'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
;
