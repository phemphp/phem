<?php

declare(strict_types=1);

namespace Mamu\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Mamu\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compiles tests/Fixtures/app with mamuc (once per test run) and boots its kernel.
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
            $mamuc = getenv('MAMUC_BIN') ?: trim((string) shell_exec('command -v mamuc'));
            if ('' === $mamuc) {
                TestCase::markTestSkipped('mamuc is not installed (build it with cargo, or set MAMUC_BIN).');
            }
            exec(\sprintf('cd %s && %s build src --no-warmup 2>&1', escapeshellarg($dir), escapeshellarg($mamuc)), $out, $code);
            if (0 !== $code) {
                TestCase::fail("mamuc build failed:\n".implode("\n", $out));
            }
            exec('rm -rf '.escapeshellarg($dir.'/var/cache/test'));
            self::$built = true;
        }
        $this->kernel = new TestKernel('test', true);
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * Sends an event to the URL of a page, as the mamu Stimulus controller does.
     *
     * @param array<string, mixed>|string $payload the JSON body, or its data
     * @param array<string, string|null> $server  more headers (`HTTP_ORIGIN`) or other values; null removes a default
     */
    private function event(string $uri, array|string $payload, array $server = []): Response
    {
        $server += ['HTTP_X_MAMU_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        return $this->request('POST', $uri, \is_string($payload) ? $payload : json_encode($payload), array_filter($server, static fn (?string $v): bool => null !== $v));
    }

    /**
     * Handles a request like a server: handle(), then terminate().
     *
     * @param array<string, string> $server
     */
    private function request(string $method, string $uri, ?string $body = null, array $server = []): Response
    {
        $kernel = $this->fixtureKernel();
        $request = Request::create($uri, $method, server: $server + ['CONTENT_TYPE' => 'application/json'], content: $body);
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
