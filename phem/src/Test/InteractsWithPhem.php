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

namespace Phem\Test;

use Phem\BrowserEventDispatcher;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Renderer;
use Phem\State\BoundValues;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Phem\State\UrlQuery;
use Phem\Test\Internal\ComponentDriver;
use Phem\Test\Internal\PageDriver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tests the components and the pages of an application, with no browser. Use it in a
 * KernelTestCase (`framework.test: true`):
 *
 *     final class CounterTest extends KernelTestCase
 *     {
 *         use InteractsWithPhem;
 *
 *         public function testIncrement(): void
 *         {
 *             $this->mount(Counter::class, ['label' => 'Clicks'])
 *                 ->click('button')
 *                 ->assertSee('Clicks 1 times')
 *                 ->assertState('count', 1);
 *         }
 *
 *         public function testPage(): void
 *         {
 *             $this->visit('/counters')
 *                 ->click('button')
 *                 ->assertSee('Clicked 1 times');
 *         }
 *     }
 *
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithPhem
{
    /**
     * Renders a component with its props, in the process of the test. Its events run as in the
     * EventController, with no HTTP request: the access rules of a page do not apply. Give a
     * slot (`children`) as a Phem\Html value.
     *
     * @param class-string|string  $component the class of a class component, or the name of a function component
     * @param array<string, mixed> $props
     */
    protected function mount(string $component, array $props = []): ComponentTester
    {
        $container = static::getContainer();
        $requests = $container->has('request_stack') ? $container->get('request_stack') : null;
        $resetter = $container->has('services_resetter') ? $container->get('services_resetter') : null;
        $sessions = $container->has('session.factory') ? $container->get('session.factory') : null;
        $renderer = $container->get('phem.renderer');
        $accessor = $container->get('phem.state_accessor');
        $codec = $container->get('phem.state_codec');
        $dispatcher = $container->get('phem.browser_event_dispatcher');
        $metadata = $container->get('phem.metadata');
        \assert($renderer instanceof Renderer && $accessor instanceof StateAccessor && $codec instanceof StateCodec && $dispatcher instanceof BrowserEventDispatcher && $metadata instanceof ComponentMetadata);

        return new ComponentTester(new ComponentDriver(
            $renderer,
            $accessor,
            $codec,
            ltrim($component, '\\'),
            $props,
            $requests instanceof RequestStack ? $requests : null,
            $resetter instanceof ResetInterface ? $resetter : null,
            $sessions instanceof SessionFactoryInterface ? $sessions->createSession() : null,
            $dispatcher,
            new BoundValues($metadata),
            new UrlQuery($metadata),
        ));
    }

    /**
     * Loads a page with the HTTP kernel. Its events go to the URL of the page, as from the
     * browser: the access rules of the page and the signature of the state apply. The cookies
     * of the responses (the session) go with the next requests.
     *
     * @param array<string, string> $server more server values and headers for each request (`HTTP_ACCEPT_LANGUAGE`)
     */
    protected function visit(string $uri, array $server = []): ComponentTester
    {
        return $this->visitWithCookies($uri, $server, []);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    private function visitWithCookies(string $uri, array $server, array $cookies): ComponentTester
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface && null !== static::$kernel);
        $driver = new PageDriver(static::$kernel, $dispatcher, $uri, $server, $cookies);

        return new ComponentTester($driver, fn (string $url): ComponentTester => $this->visitWithCookies($url, $server, $driver->cookies()));
    }
}
