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

use Phem\Argument\CallableArguments;
use Phem\Event;
use Phem\Handler\HandlerCall;
use Phem\Handler\HandlerFingerprint;
use Phem\Handler\HandlerInvoker;
use Phem\Tests\Fixtures\Rows;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\Size;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A handler call with arguments in the markup: `onClick={{ remove(scientist) }}`
 * (tests/Fixtures/app/src/Ui/Calls/CallsPage.phem).
 */
final class HandlerCallTest extends TestCase
{
    use FixtureApp;

    protected function setUp(): void
    {
        Rows::reset();
    }

    public function testTheHandlerGetsTheArgumentsOfTheRenderAndTheEvent(): void
    {
        [$state, $signature, $handlers] = $this->page();

        $response = $this->click($handlers['Hedy'], $state, $signature);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([3], Rows::$removed);
        self::assertStringContainsString('click Hedy', (string) $response->getContent());
    }

    public function testAClickOnARemovedRowReloadsThePage(): void
    {
        [$state, $signature, $handlers] = $this->page();
        Rows::remove(2); // another user removes Grace

        self::assertSame(409, $this->click($handlers['Grace'], $state, $signature)->getStatusCode());
        self::assertSame(200, $this->click($handlers['Marie'], $state, $signature)->getStatusCode());
        self::assertSame([2, 4], Rows::$removed, 'the click on Grace runs no other handler');
    }

    public function testAChildAddsItsArgumentsAfterTheArgumentsOfTheParent(): void
    {
        [$state, $signature, , $sizes] = $this->page();

        $response = $this->click($sizes['Large'], $state, $signature);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('picker Large', (string) $response->getContent());
    }

    public function testEachArgumentGivesAnotherHandlerId(): void
    {
        [, , $handlers, $sizes] = $this->page();

        self::assertCount(4, array_unique($handlers));
        self::assertCount(2, array_unique($sizes));
    }

    public function testACallKeepsTheObjectOfTheHandler(): void
    {
        $component = new class {
            /** @var list<mixed> */
            public array $calls = [];

            public function pick(string $source, Size $size, Event $e): void
            {
                $this->calls[] = [$source, $size, $e->type];
            }
        };

        $call = HandlerCall::bind(HandlerCall::bind($component->pick(...), ['menu']), [Size::Small]);

        self::assertNotNull($call);
        self::assertSame($component, new \ReflectionFunction($call)->getClosureThis(), 'the object is the owner of the handler');
        self::assertSame([$component->pick(...)::class, ['menu', Size::Small]], [HandlerCall::unwrap($call)[0]::class, HandlerCall::unwrap($call)[1]]);

        new HandlerInvoker()->invoke($call, new Event('click'));

        self::assertSame([['menu', Size::Small, 'click']], $component->calls);
    }

    public function testNoHandlerGivesNoCall(): void
    {
        self::assertNull(HandlerCall::bind(null, [1]));

        $this->expectException(\InvalidArgumentException::class);
        HandlerCall::bind('system', ['ls']);
    }

    public function testTheFingerprintHasTheArguments(): void
    {
        $fingerprints = new HandlerFingerprint();
        $remove = static fn (Scientist $s): null => null;

        self::assertSame($fingerprints->of(HandlerCall::bind($remove, [new Scientist(1, 'Ada')]) ?? $remove), $fingerprints->of(HandlerCall::bind($remove, [new Scientist(1, 'Ada')]) ?? $remove));
        self::assertNotSame($fingerprints->of(HandlerCall::bind($remove, [new Scientist(1, 'Ada')]) ?? $remove), $fingerprints->of(HandlerCall::bind($remove, [new Scientist(2, 'Grace')]) ?? $remove));
        self::assertSame($fingerprints->of($remove), $fingerprints->of(HandlerCall::bind($remove, []) ?? $remove), 'a call with no arguments is the handler');
    }

    public function testACallWithMoreArgumentsThanParametersFails(): void
    {
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('has 1 parameters, but its call in the markup has 2 arguments');

        new CallableArguments()->of(static fn (int $index): null => null, bound: [1, 2]);
    }

    public function testAVariadicParameterGetsTheOtherArguments(): void
    {
        $arguments = new CallableArguments()->of(static fn (string $first, int ...$rest): null => null, bound: ['a', 1, 2]);

        self::assertSame(['a', 1, 2], $arguments);
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: array<string, string>} the state, its signature, the handler of each row, and the handler of each size */
    private function page(): array
    {
        $html = (string) $this->request('GET', '/calls')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m), $html);
        preg_match_all('#<li data-phem-key="\d+">(\w+) <button data-phem-on-click="([^"]+)"#', $html, $rows, \PREG_SET_ORDER);
        preg_match_all('#<button data-phem-key="\w+" data-phem-on-click="([^"]+)">(\w+)</button>#', $html, $sizes, \PREG_SET_ORDER);
        self::assertCount(4, $rows, $html);
        self::assertCount(2, $sizes, $html);

        return [html_entity_decode($m[1]), $m[2], array_column($rows, 2, 1), array_column($sizes, 1, 2)];
    }

    private function click(string $handler, string $state, string $signature): Response
    {
        return $this->event('/calls', ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click']]);
    }
}
