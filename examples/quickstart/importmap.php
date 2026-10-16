<?php

/**
 * The importmap of the pages: `{{ importmap('app') }}` in templates/base.html.twig.
 *
 * `bin/console importmap:install` downloads the packages with a version to assets/vendor/
 * (Composer runs it after `composer install`).
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
];
