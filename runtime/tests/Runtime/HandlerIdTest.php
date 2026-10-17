<?php

declare(strict_types=1);

namespace Mamu\Tests\Runtime;

use Mamu\Handler\HandlerFingerprint;
use Mamu\Handler\ObjectIdentifier;
use Mamu\Rendering\Tree;
use Mamu\Tests\Fixtures\Rows;
use Mamu\Tests\Fixtures\Scientist;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A handler id comes from the handler, not from its position: a click never runs the handler
 * of another row (tests/Fixtures/app/src/pages/rows.phpm).
 */
final class HandlerIdTest extends TestCase
{
    use FixtureApp;

    protected function setUp(): void
    {
        Rows::reset();
    }

    public function testClickRunsTheHandlerOfItsRowAfterAnotherRowIsRemoved(): void
    {
        [$state, $signature, $handlers] = $this->rows();
        Rows::remove(2); // another user removes Grace

        $response = $this->remove($handlers['Hedy'], $state, $signature);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([2, 3], Rows::$removed, 'Hedy, not Marie');
        self::assertSame(['Ada', 'Marie'], array_values(array_map(static fn (Scientist $s): string => $s->name, Rows::$scientists)));
    }

    public function testClickOnARemovedRowReloadsThePage(): void
    {
        [$state, $signature, $handlers] = $this->rows();
        Rows::remove(2);

        self::assertSame(409, $this->remove($handlers['Grace'], $state, $signature)->getStatusCode());
        self::assertSame([2], Rows::$removed, 'no other row');
    }

    public function testFingerprintComesFromTheFunctionAndTheCapturedValues(): void
    {
        $fingerprints = new HandlerFingerprint();
        $handlers = static fn (mixed $value): array => [
            static fn () => $value,
            static fn () => $value,
        ];

        [$a, $b] = $handlers(1);
        self::assertNotSame($fingerprints->of($a), $fingerprints->of($b), 'another line');
        self::assertSame($fingerprints->of($handlers(1)[0]), $fingerprints->of($a), 'the same function and value: the next request');
        self::assertNotSame($fingerprints->of($handlers(2)[0]), $fingerprints->of($a));
        self::assertSame($fingerprints->of($handlers(new Scientist(1, 'Ada'))[0]), $fingerprints->of($handlers(new Scientist(1, 'Ada'))[0]), 'an object by its public properties');
        self::assertNotSame($fingerprints->of($handlers(new Scientist(1, 'Ada'))[0]), $fingerprints->of($handlers(new Scientist(3, 'Hedy'))[0]));
    }

    public function testFingerprintUsesTheIdentityOfAnObject(): void
    {
        $byId = new HandlerFingerprint(new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                return $value instanceof Scientist ? ['id' => $value->id] : null;
            }
        });
        $handler = static fn (Scientist $s): \Closure => static fn () => $s;

        self::assertSame($byId->of($handler(new Scientist(1, 'Ada'))), $byId->of($handler(new Scientist(1, 'Ada Lovelace'))), 'the same entity, with a changed name');
        self::assertNotSame($byId->of($handler(new Scientist(1, 'Ada'))), $byId->of($handler(new Scientist(2, 'Ada'))));
    }

    public function testSameHandlerTwiceGetsANumber(): void
    {
        $tree = new Tree();
        $tree->begin();
        $handler = static fn () => null;

        self::assertSame('x.f1', $tree->addHandler($handler, 'f1'));
        self::assertSame('x.f1~2', $tree->addHandler($handler, 'f1'));
        self::assertSame('x.f2', $tree->addHandler($handler, 'f2'));
        self::assertSame($handler, $tree->handler('x.f1~2'));
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} the state, its signature, and the handler of each row by name */
    private function rows(): array
    {
        $html = (string) $this->request('GET', '/rows')->getContent();
        self::assertSame(1, preg_match('/data-mamu-state-value="([^"]*)" data-mamu-signature-value="([^"]*)"/', $html, $m));
        preg_match_all('#<li data-mamu-key="\d+">(\w+) <button data-mamu-on-click="([^"]+)"#', $html, $rows, \PREG_SET_ORDER);
        self::assertCount(4, $rows);

        return [html_entity_decode($m[1]), $m[2], array_column($rows, 2, 1)];
    }

    private function remove(string $handler, string $state, string $signature): Response
    {
        return $this->event('/rows', ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click']]);
    }
}
