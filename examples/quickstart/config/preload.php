<?php

// OPcache preload (docker/php-prod.ini of the quickstart): the Symfony container and the compiled components.

if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}

if (file_exists(dirname(__DIR__).'/var/cache/phem/preload.php')) {
    require dirname(__DIR__).'/var/cache/phem/preload.php';
}
