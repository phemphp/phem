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

use Phem\State\PageState;
use Phem\State\TreeShape;
use PHPUnit\Framework\TestCase;

final class TreeShapeTest extends TestCase
{
    use FixtureApp;

    public function testPageStateKeepsTheShapeInTheTreeKey(): void
    {
        $shape = new TreeShape(['phem1' => [null, null], 'phem2' => ['phem1', 'abc']]);
        $state = new PageState(['phem2' => ['n' => 1]], [], $shape);

        self::assertSame(['_tree' => ['phem1' => [null, null], 'phem2' => ['phem1', 'abc']], 'phem2' => ['n' => 1]], $state->toArray());
        self::assertEquals($state, PageState::fromArray($state->toArray()));
        self::assertSame(['phem2' => ['n' => 1]], PageState::fromArray($state->toArray())->fibers);
        self::assertSame('abc', $shape->hashOf('phem2'));
        self::assertSame('phem1', $shape->parentOf('phem2'));
        self::assertNull($shape->parentOf('phem1'));
        self::assertNull($shape->hashOf('unknown'));
    }

    public function testShapeIgnoresEntriesWithAnotherForm(): void
    {
        $shape = TreeShape::fromArray(['phem1' => [null, 'a'], 'phem2' => 'x', 'phem3' => [1, 'a'], 'phem4' => ['phem1'], 'phem5' => ['p' => 'phem1', 'h' => 'a'], 7 => [null, 'a']]);

        self::assertSame(['phem1' => [null, 'a']], $shape->fibers);
        self::assertSame([], TreeShape::fromArray('not an array')->fibers);
    }

    public function testPageStateHasTheShapeOfTheRender(): void
    {
        $tree = $this->shapeOf($this->state((string) $this->request('GET', '/')->getContent()));

        $roots = array_keys(array_filter($tree, static fn (array $f): bool => null === $f[0]));
        self::assertCount(1, $roots, 'only the page has no parent');
        self::assertNull($tree[$roots[0]][1], 'the page is around the root element: no hash');
        foreach ($tree as $id => [$parent, $hash]) {
            self::assertTrue(null === $parent || isset($tree[$parent]), \sprintf('the parent of "%s" is in the shape', $id));
            self::assertTrue(null === $hash || 1 === preg_match('/^[0-9a-f]{16}$/', $hash), \sprintf('the hash of "%s" is an xxh3 hash', $id));
        }
        self::assertNotEmpty(array_filter(array_column($tree, 1)), 'the fibers in the root element have a hash');
    }

    public function testEventChangesTheHashesOfTheChangedFibersOnly(): void
    {
        $html = (string) $this->request('GET', '/')->getContent();
        $state = $this->state($html);
        self::assertSame(1, preg_match('/data-phem-signature-value="([^"]*)"/', $html, $s));
        self::assertSame(1, preg_match('/<button id="toggle"[^>]*data-phem-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/', ['state' => $state, 'signature' => $s[1], 'handler' => $h[1], 'event' => ['type' => 'click']]);
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(200, $response->getStatusCode());

        $before = $this->shapeOf($state);
        $after = $this->shapeOf($data['state']);
        self::assertSame(array_keys($before), array_keys($after), 'the same fibers');
        $changed = array_keys(array_filter($after, static fn (array $f, string $id): bool => $f !== $before[$id], \ARRAY_FILTER_USE_BOTH));
        preg_match_all('/<turbo-stream [^>]*target="([^"]+)"/', $data['streams'], $targets);
        self::assertSame($targets[1], $changed);
        self::assertCount(1, $changed, 'only the Toggle');
    }

    private function state(string $html): string
    {
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)"/', $html, $m));

        return html_entity_decode($m[1]);
    }

    /** @return array<string, array{0: string|null, 1: string|null}> */
    private function shapeOf(string $state): array
    {
        $data = json_decode($state, true);
        self::assertIsArray($data['_tree'] ?? null);

        return $data['_tree'];
    }
}
