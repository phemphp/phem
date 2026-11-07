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

use Phem\Async\AsyncHandler;
use Phem\Async\MemoryBus;
use Phem\BrowserEventDispatcher;
use Phem\DependencyInjection\TestAsyncPass;
use Phem\DependencyInjection\TestPublisherPass;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\EventRenderer;
use Phem\Rendering\Renderer;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Phem\State\UrlQuery;
use Phem\Stream\MemoryPublisher;
use Phem\Test\Internal\Browser;
use Phem\Test\Internal\ComponentDriver;
use Phem\Test\Internal\ErrorPageDriver;
use Phem\Test\Internal\PageDriver;
use PHPUnit\Framework\Attributes\After;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
    /** Whether visit() set the web mode of the Symfony runtime (see resetPhemRuntimeMode()). */
    private bool $phemRuntimeModeSet = false;

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
        $events = $container->get('phem.event_renderer');
        \assert($renderer instanceof Renderer && $accessor instanceof StateAccessor && $codec instanceof StateCodec && $dispatcher instanceof BrowserEventDispatcher && $metadata instanceof ComponentMetadata && $events instanceof EventRenderer);

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
            $events,
            new UrlQuery($metadata),
        ));
    }

    /**
     * Loads a page with the HTTP kernel. Its events go to the URL of the page, as from the
     * browser: the access rules of the page and the signature of the state apply. Each call is a
     * new browser, with only the cookies that you give. The cookies of the responses (the
     * session) go with the next requests, and to the pages that ComponentTester::visit() opens in
     * the same browser:
     *
     *     $this->visit('/bag', cookies: ['currency' => 'EUR'])->assertSee('0,00 €');
     *
     * @param array<string, string> $server  more server values and headers for each request (`HTTP_ACCEPT_LANGUAGE`)
     * @param array<string, string> $cookies name => value: cookies of the browser for the host of the URL, for all its paths
     */
    protected function visit(string $uri, array $server = [], array $cookies = []): ComponentTester
    {
        // As WebTestCase: in the CLI mode, Symfony renders an HTTP error with CliErrorRenderer,
        // not with the error page of the application.
        if (!isset($_SERVER['APP_RUNTIME_MODE'])) {
            $_SERVER['APP_RUNTIME_MODE'] = 'web=1';
            $this->phemRuntimeModeSet = true;
        }

        $browser = new Browser(fn (Browser $browser, string $url): ComponentTester => $this->visitIn($browser, $url, $server));

        return $browser->open('', $uri, $cookies);
    }

    /**
     * Renders the error page of an error at a URL (see Phem\Attribute\ErrorPage), as the preview
     * at `/_error/{code}` does: the debug mode of the test does not show the exception. An int is
     * the status of an HttpException. With no error page for the status and the URL, the page of
     * Symfony renders. An error page has no events:
     *
     *     $this->errorPage(404, '/admin/missing')->assertStatus(404)->assertSee('Back to the admin');
     *     $this->errorPage(new \RuntimeException('Boom.'), '/cart')->assertStatus(500)->assertDontSee('Boom.');
     */
    protected function errorPage(int|\Throwable $error, string $uri = '/'): ComponentTester
    {
        $container = static::getContainer();
        $renderer = $container->get('phem.error_page_renderer');
        $requests = $container->get('request_stack');
        $sessions = $container->has('session.factory') ? $container->get('session.factory') : null;
        \assert($renderer instanceof ErrorRendererInterface && $requests instanceof RequestStack);

        // As the error sub-request of Symfony: the session of the browser goes with it.
        $request = Request::create($uri);
        $request->attributes->set('showException', false);
        if ($sessions instanceof SessionFactoryInterface) {
            $request->setSession($sessions->createSession());
        }

        $requests->push($request);

        try {
            $exception = $renderer->render(\is_int($error) ? HttpException::fromStatusCode($error) : $error);
        } finally {
            $requests->pop();
        }

        return new ComponentTester(new ErrorPageDriver($exception->getAsString(), $exception->getStatusCode(), $uri));
    }

    /** Removes the web mode of the Symfony runtime that visit() set. */
    #[After]
    public function resetPhemRuntimeMode(): void
    {
        if ($this->phemRuntimeModeSet) {
            unset($_SERVER['APP_RUNTIME_MODE']);
            $this->phemRuntimeModeSet = false;
        }
    }

    /**
     * The Mercure hub of a test with a real browser (Playwright) whose requests the kernel of the
     * test handles, for the updates that the application publishes from now (see BrowserHub).
     */
    protected function browserHub(): BrowserHub
    {
        // With `framework.test: true` (getContainer() needs it), the streams go to memory.
        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);

        return new BrowserHub($publisher);
    }

    /**
     * Loads a page in a browser: the pages of one browser share its cookies and its server values.
     *
     * @param array<string, string> $server
     */
    private function visitIn(Browser $browser, string $uri, array $server): ComponentTester
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface && null !== static::$kernel);

        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        $updates = $publisher instanceof MemoryPublisher ? $publisher->updates(...) : null;

        $bus = static::getContainer()->has(TestAsyncPass::ID) ? static::getContainer()->get(TestAsyncPass::ID) : null;
        $handler = static::getContainer()->has('phem.async.handler') ? static::getContainer()->get('phem.async.handler') : null;

        if ($bus instanceof MemoryBus) {
            // The messages of the #[Async] handlers wait for settle().
            $bus->keep();
        }

        // As a worker: the messages of the #[Async] handlers, in their order.
        $settle = $bus instanceof MemoryBus && $handler instanceof AsyncHandler ? static function () use ($bus, $handler): int {
            $messages = $bus->take();
            foreach ($messages as $message) {
                $handler($message);
            }

            return \count($messages);
        } : null;

        $driver = new PageDriver(static::$kernel, $dispatcher, $uri, $server, $browser->jar());

        return new ComponentTester($driver, $browser, $updates, $settle);
    }
}
