<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Tests\Runtime;

use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compiles tests/Fixtures/app with phemc (once per test run, see TestKernel::compileSources()) and boots its kernel.
 */
trait FixtureApp
{
    private ?TestKernel $kernel = null;

    public function fixtureKernel(): TestKernel
    {
        if (null !== $this->kernel) {
            return $this->kernel;
        }

        TestKernel::compileSources();
        $this->kernel = new TestKernel('test', true);
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * Sends an event to the URL of a page, as the phem Stimulus controller does.
     *
     * @param array<string, mixed>|string $payload the JSON body, or its data
     * @param array<string, string|null>  $server  more headers (`HTTP_ORIGIN`) or other values; null removes a default
     */
    private function event(string $uri, array|string $payload, array $server = []): Response
    {
        $server += ['HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        return $this->request('POST', $uri, \is_string($payload) ? $payload : json_encode($payload, \JSON_THROW_ON_ERROR), array_filter($server, static fn (?string $v): bool => null !== $v));
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

    /**
     * A service of the fixture app, also a private one.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $container = $this->fixtureKernel()->getContainer()->get('test.service_container');
        TestCase::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        TestCase::assertInstanceOf($class, $service);

        return $service;
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }
}
