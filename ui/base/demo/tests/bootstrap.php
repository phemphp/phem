<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The tests boot the kernel without Symfony Runtime: load .env (APP_SECRET) here. In the test
// environment, Dotenv does not read .env.local.
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');
