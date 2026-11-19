<?php

use App\Kernel;

// FrankenPHP runs this script in worker mode (etc/Caddyfile). The files of public/assets/ come from
// asset-map:compile; in the dev and test environments, AssetMapper serves /assets/.
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'] ?? 'dev', (bool) ($context['APP_DEBUG'] ?? true));
