<?php

// The front controller. FrankenPHP runs it in worker mode (see the Caddyfile): the kernel
// boots once, handles many requests, and resets the services (`kernel.reset`) between them.
// It also works with PHP-FPM.

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
