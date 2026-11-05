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

use Phem\State\StateJson;
use Phem\Tests\Fixtures\Many;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pages with many components (tests/Fixtures/app/src/Ui/Many/ManyPage.phpm): each component keeps its
 * own HTML, its handlers and its bound fields, an event answers with only the state that changed,
 * and the render time grows linearly with the number of components.
 *
 * With no `kernel.debug`: the dev-mode check renders the full page again (see SkippedRenderCheck).
 */
final class ManyComponentsTest extends TestCase
{
    use FixtureApp;

    /** The rows of the small and of the large render: 16 times more. */
    private const SMALL = 100;
    private const LARGE = 1600;

    /**
     * The maximum time of the large render, as a factor of the time of the small render. A linear
     * render is about 10 to 13 times slower (the fixed work of a request is the same). A render
     * that reads the HTML or the fibers of the page again for each row is 28 to 66 times slower.
     */
    private const MAX_FACTOR = 20;

    /**
     * The levels of the small and of the large deep page: 8 times more. The HTML of a level has
     * all the levels under it, so the strings that the render copies are not linear, and a linear
     * render is about 17 to 20 times slower. A render that scans the HTML of each level again (to
     * find its root element) is about 40 times slower.
     */
    private const SMALL_DEPTH = 50;
    private const LARGE_DEPTH = 400;
    private const MAX_DEPTH_FACTOR = 26;

    public function fixtureKernel(): TestKernel
    {
        if (null === $this->kernel) {
            $this->kernel = new TestKernel('nodebug', false);
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    protected function setUp(): void
    {
        Many::$rows = 50;
    }

    public function testAClickInARowUpdatesOnlyThatRow(): void
    {
        [$html, $state, $signature] = $this->open('/many');
        [$row, $handler] = self::row($html, 37);

        $data = $this->send('/many', $state, $signature, $handler);

        self::assertSame([$row], self::targets($data['streams']));
        self::assertStringContainsString('Row 37: 1 clicks', $data['streams']);
    }

    public function testAnEventAnswersWithOnlyTheStateThatChanged(): void
    {
        [$html, $state, $signature] = $this->open('/many');
        [$row, $handler] = self::row($html, 37);

        $data = $this->send('/many', $state, $signature, $handler);

        self::assertSame(['_tree', $row], array_keys($data['changes']), 'the shape, with the new hash of the row, and the state of the row');
        self::assertSame('{"clicks":1}', $data['changes'][$row]);
        self::assertCount(1, $data['changes']['_tree'], 'a patch of the shape: one splice');
        self::assertSame([1, 3], [$data['changes']['_tree'][0][1], \count($data['changes']['_tree'][0])], 'only the entry of the row');

        // The browser sets the changes in its state. The server accepts the text: it is the text
        // that the server signed.
        $data = $this->send('/many', StateJson::apply($state, $data['changes']), $data['signature'], $handler);
        self::assertStringContainsString('Row 37: 2 clicks', $data['streams']);
    }

    public function testAChangeOfOneItemOfALargeStateSendsAPatchOfItsText(): void
    {
        [$html, $state, $signature] = $this->open('/many-items');
        self::assertSame(1, preg_match('/<button id="toggle" data-phem-on-click="([^"]+)"/', $html, $m));
        $handler = $m[1];
        // The entry of the page: the state of its fiber has the list.
        $page = array_key_first(array_filter((array) StateJson::entries($state), static fn (string $json): bool => str_starts_with($json, '{"items":')));

        $data = $this->send('/many-items', $state, $signature, $handler);

        self::assertStringContainsString('Done: yes', $data['streams']);
        self::assertSame([[5, 1, '"done":true}']], $data['changes'][$page], 'one part of the text of the list: the "done" of the second item');

        // The browser applies the patch. The server accepts the text: it is the text that the server signed.
        $data = $this->send('/many-items', StateJson::apply($state, $data['changes']), $data['signature'], $handler);
        self::assertStringContainsString('Done: no', $data['streams']);
        self::assertSame([[5, 1, '"done":false}']], $data['changes'][$page]);
    }

    public function testARequestLeavesNoCyclesForTheCollector(): void
    {
        // The first request builds the container and compiles the templates.
        [$html, $state, $signature] = $this->open('/many');
        self::assertSame(1, preg_match('/<button id="bump" data-phem-on-click="([^"]+)"/', $html, $m));
        gc_collect_cycles();

        // A worker frees the fibers of a request with no run of the collector (see Tree::__destruct()).
        $this->open('/many');
        self::assertSame(0, gc_collect_cycles(), 'the page');
        $this->send('/many', $state, $signature, $m[1]);
        self::assertSame(0, gc_collect_cycles(), 'an event');
    }

    public function testThePageRendersAgainWithTheSameHtmlAndKeepsTheRows(): void
    {
        [$html, $state, $signature] = $this->open('/many');
        self::assertSame(1, preg_match('/<button id="nothing" data-phem-on-click="([^"]+)"/', $html, $m));

        // The page renders, and the rows are kept: the HTML of the page without its rows is the
        // HTML of the first render, with the full rows in it.
        $data = $this->send('/many', $state, $signature, $m[1]);

        self::assertSame('', $data['streams']);
    }

    public function testAChangeOfThePageKeepsAllTheRowsInTheBrowser(): void
    {
        [$html, $state, $signature] = $this->open('/many');
        self::assertSame(1, preg_match('/<button id="bump" data-phem-on-click="([^"]+)"/', $html, $m));

        $data = $this->send('/many', $state, $signature, $m[1]);

        self::assertCount(1, self::targets($data['streams']), 'the root element');
        self::assertStringContainsString('Bumps 1', $data['streams']);
        [$first] = self::row($html, 0);
        [$last] = self::row($html, 49);
        self::assertSame(1, substr_count($data['streams'], 'data-phem-keep'), 'one placeholder for the run of rows');
        self::assertStringContainsString('<template data-phem-keep="'.$first.'..'.$last.'"></template>', $data['streams']);
        self::assertSame([[1, 1]], array_map(static fn (array $splice): array => \array_slice($splice, 0, 2), $data['changes']['_tree']), 'a patch of the shape: only the entry of the page, after the document');
    }

    public function testEachFieldBindsThePropertyOfItsRow(): void
    {
        [$html, $state, $signature] = $this->open('/many-fields');
        self::assertSame(1, preg_match_all('/data-phem-bind="([^"]+)\.title"/', $html, $title));
        preg_match_all('/<li data-phem-c="(phem[0-9a-f]{12})">\s*<input name="name" data-phem-bind="([^"]+)"/', $html, $rows);
        self::assertCount(50, array_unique($rows[1]), 'a fiber for each row');
        self::assertNotContains($title[1][0], $rows[1], 'the title is a field of the page');

        foreach ($rows[1] as $i => $fiber) {
            self::assertSame($fiber.'.name', $rows[2][$i]);
            foreach (['email', 'city', 'done'] as $property) {
                self::assertStringContainsString('data-phem-bind="'.$fiber.'.'.$property.'"', $html);
            }
        }

        $data = $this->send('/many-fields', $state, $signature, null, [$rows[1][41].'.name' => 'Ada']);

        self::assertSame([$rows[1][41]], self::targets($data['streams']));
        self::assertStringContainsString('Row 41: Ada', $data['streams']);
    }

    public function testAFieldInTheSlotOfAComponentThatRendersAloneBindsThePropertyOfThePage(): void
    {
        [$html, $state, $signature] = $this->open('/many-fields');
        self::assertSame(1, preg_match('/data-phem-bind="([^"]+\.title)"/', $html, $title));
        self::assertSame(1, preg_match('/<button id="open" data-phem-on-click="([^"]+)"/', $html, $m));

        // The panel renders alone, with the slot of the page: the page does not render.
        $data = $this->send('/many-fields', $state, $signature, $m[1]);

        self::assertCount(1, self::targets($data['streams']), 'the panel');
        self::assertStringContainsString('Opens 1', $data['streams']);
        self::assertStringContainsString('data-phem-bind="'.$title[1].'"', $data['streams']);
    }

    #[Group('performance')]
    public function testTheRenderTimeGrowsLinearlyWithTheNumberOfComponents(): void
    {
        $this->assertLinear('/many');
    }

    #[Group('performance')]
    public function testTheRenderTimeGrowsLinearlyWithTheNumberOfBoundFields(): void
    {
        $this->assertLinear('/many-fields');
    }

    #[Group('performance')]
    public function testTheRenderTimeGrowsLinearlyWithTheDepth(): void
    {
        $this->assertLinear('/many-levels', self::SMALL_DEPTH, self::LARGE_DEPTH, self::MAX_DEPTH_FACTOR);
    }

    private function assertLinear(string $uri, int $small = self::SMALL, int $large = self::LARGE, int $maxFactor = self::MAX_FACTOR): void
    {
        // The first request builds the container and compiles the templates.
        $this->renderTime($uri, 20);
        $smallTime = $this->renderTime($uri, $small);
        $largeTime = $this->renderTime($uri, $large);

        self::assertLessThan($maxFactor, $largeTime / $smallTime, \sprintf('%s: size %d in %.1f ms, size %d in %.1f ms (%.1f times).', $uri, $small, $smallTime * 1e3, $large, $largeTime * 1e3, $largeTime / $smallTime));
    }

    /** The shortest time of five renders of a page with this size (its rows, or its levels), in seconds. */
    private function renderTime(string $uri, int $size): float
    {
        Many::$rows = Many::$levels = $size;
        $best = \INF;

        for ($i = 0; $i < 5; ++$i) {
            // The fibers of a request are cycles: the collector frees them before the render, not in it.
            gc_collect_cycles();

            $start = hrtime(true);
            $response = $this->request('GET', $uri);
            $best = min($best, hrtime(true) - $start);
            self::assertSame(200, $response->getStatusCode());
        }

        return $best / 1e9;
    }

    /** @return array{0: string, 1: string, 2: string} the HTML, the state and its signature */
    private function open(string $uri): array
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m), $html);

        return [$html, html_entity_decode($m[1]), $m[2]];
    }

    /**
     * @param array<string, mixed> $bound
     *
     * @return array<string, mixed>
     */
    private function send(string $uri, string $state, string $signature, ?string $handler, array $bound = []): array
    {
        $response = $this->event($uri, ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click'], 'bound' => (object) $bound]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: string} the fiber id of a row of /many, and the id of its handler */
    private static function row(string $html, int $index): array
    {
        self::assertSame(1, preg_match('/<li data-phem-c="(phem[0-9a-f]{12})">\s*<button data-phem-on-click="([^"]+)">Row '.$index.':/', $html, $m));

        return [$m[1], $m[2]];
    }

    /** @return list<string> the target of each stream */
    private static function targets(string $streams): array
    {
        preg_match_all('/<turbo-stream [^>]*targets="\[data-phem-c=([^"\]]+)\]"/', $streams, $m);

        return $m[1];
    }
}
