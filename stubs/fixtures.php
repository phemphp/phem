<?php

// The classes of the .phem fixtures that the tests use, for PHPStan: phemc compiles the
// fixtures into the var/ of the test app, which PHPStan does not read.

namespace App\Ui\Redirect {
    final class RedirectSwitch
    {
        public static bool $on = false;
    }
}

namespace App\Ui\Async {
    final class AsyncRedirectSwitch
    {
        public static bool $on = false;
        public static bool $ran = false;
    }
}
