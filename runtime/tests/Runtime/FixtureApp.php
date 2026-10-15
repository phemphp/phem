<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Runtime;

/**
 * Compiles tests/Fixtures/app with rhpc (once) and boots the runtime on it.
 */
trait FixtureApp
{
    private static ?Runtime $runtime = null;

    public static function fixtureRuntime(): Runtime
    {
        if (null !== self::$runtime) {
            return self::$runtime;
        }
        $dir = \dirname(__DIR__).'/Fixtures/app';
        $rhpc = getenv('RHPC_BIN') ?: trim((string) shell_exec('command -v rhpc'));
        if ('' === $rhpc) {
            TestCase::markTestSkipped('rhpc is not installed (build it with cargo, or set RHPC_BIN).');
        }
        exec(\sprintf('cd %s && %s build src --no-warmup 2>&1', escapeshellarg($dir), escapeshellarg($rhpc)), $out, $code);
        if (0 !== $code) {
            TestCase::fail("rhpc build failed:\n".implode("\n", $out));
        }
        putenv('RHP_SECRET=test-secret');
        $runtime = new Runtime($dir, $dir.'/var/cache/rhp', true);
        Runtime::set($runtime);
        $runtime->warmup();

        return self::$runtime = $runtime;
    }
}
