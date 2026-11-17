<?php

use App\Kernel;

// The built-in web server of PHP gives each request to this script: AssetMapper serves /assets/.
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'] ?? 'dev', (bool) ($context['APP_DEBUG'] ?? true));
