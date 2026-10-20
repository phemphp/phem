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

use Phem\Profiler\PhemDataCollector;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * The Phem panel of the Symfony profiler (see Phem\Profiler\PhemDataCollector).
 */
final class ProfilerTest extends TestCase
{
    use FixtureApp;
    use SkipPage;

    public function fixtureKernel(): TestKernel
    {
        if (null === $this->kernel) {
            TestKernel::compileSources();
            $this->kernel = new TestKernel('profiler', true);
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    public function testThePanelOfAPage(): void
    {
        $collector = $this->collector($this->request('GET', '/skip'));

        self::assertTrue($collector->isPhem());
        self::assertFalse($collector->isEvent());
        self::assertSame('App\Pages\SkipPage', $collector->getPage());
        self::assertSame('rendered', $collector->getOutcome());
        self::assertSame(['Render'], array_column($collector->getPasses(), 'label'));
        self::assertSame('SkipPage', $collector->getFibers()[0]['name']);
        self::assertSame(0, $collector->getFibers()[0]['depth']);
        self::assertContains('Clicker', array_column($collector->getFibers(), 'name'));
        self::assertSame(\count($collector->getFibers()), $collector->getRenderedCount());
        self::assertGreaterThan(0, $collector->getState()['size'] ?? 0);
        self::assertNull($collector->getHandler());
    }

    public function testThePanelOfAnEvent(): void
    {
        $this->click('clicker');
        $collector = $this->collector($this->lastResponse());

        self::assertTrue($collector->isEvent());
        self::assertSame('rendered', $collector->getOutcome());
        self::assertSame('Clicker::click()', $collector->getHandler()['name'] ?? null);
        self::assertNull($collector->getHandler()['exception'] ?? null);
        self::assertTrue($collector->isStateSent());
        self::assertSame(['Before the handler', 'After the handler', 'Dev-mode check'], array_column($collector->getPasses(), 'label'));
        self::assertCount(2, $collector->getMainPasses());

        $fibers = array_column($collector->getFibers(), null, 'name');
        self::assertTrue($fibers['Clicker']['changed']);
        self::assertArrayHasKey(2, $fibers['Clicker']['renders'], 'the clicker renders after the handler');
        self::assertFalse($fibers['Probe']['changed']);
        self::assertArrayNotHasKey(2, $fibers['Probe']['renders'], 'a probe is kept after the handler');
        self::assertSame(1, $collector->getChangedCount());
        self::assertNull($collector->getTransaction(), 'no Doctrine in the fixture app');
    }

    public function testTheProfilerShowsThePanel(): void
    {
        $this->click('clicker');
        $token = $this->lastResponse()->headers->get('X-Debug-Token');
        self::assertNotNull($token);

        $panel = $this->request('GET', '/_profiler/'.$token.'?panel=phem');

        self::assertSame(200, $panel->getStatusCode(), (string) $panel->getContent());
        $html = (string) $panel->getContent();
        self::assertStringContainsString('Before the handler', $html);
        self::assertStringContainsString('Clicker::click()', $html);
        self::assertStringContainsString('&lt;Clicker&gt;', $html);
    }

    public function testAnotherRequestHasNoPanel(): void
    {
        $collector = $this->service('phem.data_collector', PhemDataCollector::class);
        $collector->collect(Request::create('/other'), new Response('other'));

        self::assertFalse($collector->isPhem());
        self::assertSame([], $collector->getFibers());
    }

    private ?Response $last = null;

    private function lastResponse(): Response
    {
        return $this->last ?? throw new \LogicException('No event.');
    }

    /**
     * Keeps the response of each event of SkipPage::click().
     *
     * @param array<string, mixed>|string $payload
     * @param array<string, string|null>  $server
     */
    private function event(string $uri, array|string $payload, array $server = []): Response
    {
        $server += ['HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        return $this->last = $this->request('POST', $uri, \is_string($payload) ? $payload : json_encode($payload, \JSON_THROW_ON_ERROR), array_filter($server, static fn (?string $v): bool => null !== $v));
    }

    private function collector(Response $response): PhemDataCollector
    {
        $profiler = $this->service('profiler', Profiler::class);
        $profile = $profiler->loadProfileFromResponse($response);
        self::assertNotNull($profile, 'the response has a profile');
        $collector = $profile->getCollector('phem');
        self::assertInstanceOf(PhemDataCollector::class, $collector);

        return $collector;
    }
}
