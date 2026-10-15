<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compiles tests/Fixtures/app with rhpc (once per test run) and boots its kernel.
 */
trait FixtureApp
{
    private static bool $built = false;

    private ?TestKernel $kernel = null;

    public function fixtureKernel(): TestKernel
    {
        if (null !== $this->kernel) {
            return $this->kernel;
        }
        $dir = \dirname(__DIR__).'/Fixtures/app';
        if (!self::$built) {
            $rhpc = getenv('RHPC_BIN') ?: trim((string) shell_exec('command -v rhpc'));
            if ('' === $rhpc) {
                TestCase::markTestSkipped('rhpc is not installed (build it with cargo, or set RHPC_BIN).');
            }
            exec(\sprintf('cd %s && %s build src --no-warmup 2>&1', escapeshellarg($dir), escapeshellarg($rhpc)), $out, $code);
            if (0 !== $code) {
                TestCase::fail("rhpc build failed:\n".implode("\n", $out));
            }
            exec('rm -rf '.escapeshellarg($dir.'/var/cache/test'));
            self::$built = true;
        }
        $this->kernel = new TestKernel('test', true);
        $this->kernel->boot();

        return $this->kernel;
    }

    /** Handles a request like a server: handle(), then terminate(). */
    private function request(string $method, string $uri, ?string $body = null): Response
    {
        $kernel = $this->fixtureKernel();
        $request = Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }
}
