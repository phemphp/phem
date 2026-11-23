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
use Phem\Tests\Fixtures\HandlerCalls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser sends no click to a disabled form control or an inert element, so an event for one
 * of their handlers is a click that waited in the queue of the browser, or a request that a user
 * wrote by hand: it runs no handler, and the page stays as it is
 * (tests/Fixtures/app/src/Ui/Access/DisabledHandlersPage.phem).
 */
final class DisabledHandlersTest extends TestCase
{
    use FixtureApp;

    protected function setUp(): void
    {
        HandlerCalls::$calls = [];
    }

    public function testHandlerOfADisabledOrInertElementDoesNotRun(): void
    {
        [$state, $signature, $handlers] = $this->page();

        foreach (['publish', 'off', 'inert', 'spread', 'spreadInert'] as $id) {
            self::assertSame(200, $this->send($handlers[$id], $state, $signature)->getStatusCode(), $id.': no reload');
        }

        self::assertSame([], HandlerCalls::$calls);
    }

    public function testHandlerRunsWhenTheBrowserCanSendItsEvent(): void
    {
        [$state, $signature, $handlers] = $this->page();

        self::assertSame(200, $this->send($handlers['div'], $state, $signature)->getStatusCode(), 'a div is not a form control');
        self::assertSame(200, $this->send($handlers['pointer'], $state, $signature, 'pointerdown')->getStatusCode(), 'a disabled control gets the pointer events');
        self::assertSame(200, $this->send($handlers['change'], $state, $signature, 'change')->getStatusCode(), 'a controller sends `change` to a disabled field');
        self::assertSame(['div', 'pointer', 'change'], HandlerCalls::$calls);
    }

    public function testHandlerRunsWhenTheControlIsEnabled(): void
    {
        [$state, $signature, $handlers] = $this->page();

        $response = $this->send($handlers['ready'], $state, $signature);
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $state = StateJson::apply($state, $data['changes']);

        self::assertSame(200, $this->send($handlers['publish'], $state, $data['signature'])->getStatusCode(), 'the same id: the next render registers it');
        self::assertSame(['publish'], HandlerCalls::$calls);
    }

    /**
     * The state, the signature, and the handler ids of the page, by element id.
     *
     * @return array{string, string, array<string, string>}
     */
    private function page(): array
    {
        $html = (string) $this->request('GET', '/disabled-handlers')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        preg_match_all('#id="(\w+)"[^>]*? data-phem-on-[a-z]+="([^"]+)"#', $html, $elements, \PREG_SET_ORDER);
        self::assertCount(9, $elements, 'a disabled element keeps its handler id');

        return [html_entity_decode($m[1]), $m[2], array_column($elements, 2, 1)];
    }

    private function send(string $handler, string $state, string $signature, string $type = 'click'): Response
    {
        return $this->event('/disabled-handlers', ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => $type]]);
    }
}
