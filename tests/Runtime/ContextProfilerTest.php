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
use Phem\Tests\Fixtures\Renders;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * The contexts in the Phem panel of the profiler, and the components that render because a
 * context changed (see Phem\Rendering\UpdateScope).
 */
final class ContextProfilerTest extends TestCase
{
    use FixtureApp;

    public function fixtureKernel(): TestKernel
    {
        if (null === $this->kernel) {
            $this->kernel = new TestKernel('profiler', true);
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    public function testThePanelShowsTheContextsOfEachComponent(): void
    {
        $collector = $this->collector($this->request('GET', '/context'));

        $fibers = array_values(array_filter($collector->getFibers(), static fn (array $f): bool => \in_array($f['name'], ['Tabs', 'Tab', 'ThemeScope'], true)));
        self::assertSame(['Tabs'], $fibers[0]['provides']);
        self::assertSame(['Tabs' => 'Tabs'], $fibers[1]['reads'], 'the type and the provider');
        self::assertSame(['Theme'], $fibers[3]['provides']);

        $token = $this->request('GET', '/context')->headers->get('X-Debug-Token');
        $panel = (string) $this->request('GET', '/_profiler/'.$token.'?panel=phem')->getContent();
        self::assertStringContainsString('provides Tabs', $panel);
        self::assertStringContainsString('reads Tabs from &lt;Tabs&gt;', $panel);
    }

    public function testAChangeOfAContextRendersTheConsumersUnderAKeptComponent(): void
    {
        $html = (string) $this->request('GET', '/context')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/<button id="tab-security"[^>]*data-phem-on-click="([^"]+)"/', $html, $h));
        Renders::reset();

        $response = $this->event(['state' => html_entity_decode($m[1]), 'signature' => $m[2], 'handler' => $h[1], 'event' => ['type' => 'click']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $propagated = array_column(array_filter($this->collector($response)->getFibers(), static fn (array $f): bool => $f['propagated']), 'name');
        self::assertSame(['Card', 'Tab', 'TabsNav'], $propagated, 'the card has the same props: it renders on the path to the consumers; the clicked tab is dirty');

        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('aria-selected="false"', $data['streams'], 'the other tab shows the change');
    }

    /** @param array<string, mixed> $payload */
    private function event(array $payload): Response
    {
        return $this->request('POST', '/context', json_encode($payload, \JSON_THROW_ON_ERROR), ['HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    private function collector(Response $response): PhemDataCollector
    {
        $profile = $this->service('profiler', Profiler::class)->loadProfileFromResponse($response);
        self::assertNotNull($profile, 'the response has a profile');

        $collector = $profile->getCollector('phem');
        self::assertInstanceOf(PhemDataCollector::class, $collector);

        return $collector;
    }
}
