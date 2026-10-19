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
        $hash = TreeShape::hash('<p>x</p>');
        $shape = new TreeShape(['phem000000000001' => [null, null], 'phem000000000002' => ['phem000000000001', $hash]]);
        $state = new PageState(['phem000000000002' => ['n' => 1]], [], $shape);

        self::assertSame(['_tree' => 'AAAAAAAB0,AAAAAAAC'.$hash.'1', 'phem000000000002' => ['n' => 1]], $state->toArray());
        $decoded = PageState::fromArray($state->toArray());
        self::assertSame(['phem000000000002' => ['n' => 1]], $decoded->fibers);
        self::assertSame($shape->fibers, $decoded->shape->fibers);
        self::assertSame($hash, $shape->hashOf('phem000000000002'));
        self::assertSame('phem000000000001', $shape->parentOf('phem000000000002'));
        self::assertNull($shape->parentOf('phem000000000001'));
        self::assertNull($shape->hashOf('unknown'));
    }

    public function testShapeKeepsTheTreeInItsString(): void
    {
        $a = TreeShape::hash('a');
        $b = TreeShape::hash('b');
        $fibers = [
            'phem00000000000a' => [null, null],
            'phem00000000000b' => ['phem00000000000a', $a],
            'phem00000000000c' => ['phem00000000000b', $b],
            'phemffffffffffff' => ['phem00000000000a', $a],
        ];

        $encoded = new TreeShape($fibers)->encode();

        self::assertSame('AAAAAAAK0,AAAAAAAL'.$a.'1,AAAAAAAM'.$b.'2,________'.$a.'1', $encoded);
        self::assertSame($fibers, TreeShape::decode($encoded)->fibers);
        self::assertSame(['phem00000000000b', 'phemffffffffffff'], TreeShape::decode($encoded)->childrenOf('phem00000000000a'));
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{8}$/', $a), 'the hash has 8 base64url characters');
    }

    public function testShapeWithAnotherFormIsEmpty(): void
    {
        foreach ([
            null, '', 'not a shape', ['phem000000000001' => [null, 'a']],
            'AAAAAAAB1',          // no fiber at depth 0
            'AAAAAAAB0,AAAAAAAC2', // two more depth than the fiber before
            'AAAAAAAB0,AAAAAAAB1', // the same fiber two times
            'AAAAAAAB0,AAAAAAA*1', // not base64url
            'AAAAAAAB0,AAAAAAACx', // no depth
        ] as $data) {
            self::assertSame([], TreeShape::decode($data)->fibers, json_encode($data, \JSON_THROW_ON_ERROR));
        }
    }

    public function testPageStateHasTheShapeOfTheRender(): void
    {
        $tree = $this->shapeOf($this->state((string) $this->request('GET', '/')->getContent()));

        $roots = array_keys(array_filter($tree, static fn (array $f): bool => null === $f[0]));
        self::assertCount(1, $roots, 'only the page has no parent');
        self::assertNull($tree[$roots[0]][1], 'the page is around the root element: no hash');
        foreach ($tree as $id => [$parent, $hash]) {
            self::assertTrue(null === $parent || isset($tree[$parent]), \sprintf('the parent of "%s" is in the shape', $id));
            self::assertTrue(null === $hash || 1 === preg_match('/^[A-Za-z0-9_-]{8}$/', $hash), \sprintf('the hash of "%s" is a hash (see TreeShape::hash())', $id));
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
        self::assertIsString($data['_tree'] ?? null);
        $shape = TreeShape::decode($data['_tree']);
        self::assertNotSame([], $shape->fibers);

        return $shape->fibers;
    }
}
