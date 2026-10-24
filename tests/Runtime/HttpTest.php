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

use Phem\Http\TurboStreams;
use Phem\Rendering\Fiber;
use Phem\Rendering\RootElement;
use Phem\State\PageState;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testTurboStreamsMorphTheFibers(): void
    {
        $fiber = new Fiber('phem1', '/A#0', 'A');
        $fiber->html = '<b>x</b>';

        self::assertSame('<turbo-stream action="update" method="morph" target="phem1"><template><b>x</b></template></turbo-stream>', new TurboStreams()->updates([$fiber]));
    }

    public function testPageStateKeepsTheRouteValuesInThePathKey(): void
    {
        $state = new PageState(['phem1' => ['n' => 1]], ['id' => '7']);

        self::assertSame(['_path' => ['id' => '7'], 'phem1' => ['n' => 1]], $state->toArray());
        self::assertEquals($state, PageState::fromArray($state->toArray()));
        self::assertSame(['phem1' => ['n' => 1]], new PageState(['phem1' => ['n' => 1]])->toArray());
        self::assertSame([], PageState::fromArray(['_path' => 'not an array'])->values);
    }

    public function testPageStateKeepsTheComponentsWithSubscribeInTheSubscribedKey(): void
    {
        $state = new PageState(['phem1' => ['n' => 1]], subscribed: ['App\Inbox']);

        self::assertSame(['_subscribed' => ['App\Inbox'], 'phem1' => ['n' => 1]], $state->toArray());
        self::assertEquals($state, PageState::fromArray($state->toArray()));
        self::assertSame([], PageState::fromArray(['_subscribed' => 'not an array'])->subscribed);
    }

    public function testRootElementHasTheStateOfThePage(): void
    {
        $state = RootElement::state('{"a":"<b>"}', 'sig');
        $actions = 'click->phem#dispatch dblclick->phem#dispatch input->phem#dispatch change->phem#dispatch submit->phem#dispatch keydown->phem#dispatch keyup->phem#dispatch focusin->phem#dispatch focusout->phem#dispatch';
        $actions = htmlspecialchars($actions);

        self::assertSame('<div id="phem-root" data-controller="phem" data-action="'.$actions.'" data-phem-state-value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}" data-phem-signature-value="sig"><p>x</p></div>', RootElement::render([], $state, '<p>x</p>'));
        self::assertSame(
            '<section data-controller="phem menu" data-action="'.$actions.' keydown.esc-&gt;menu#close" data-phem-state-value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}" data-phem-signature-value="sig" class="a b" hidden style="color: red"></section>',
            RootElement::render(['tag' => 'section', 'id' => null, 'data-controller' => 'menu', 'data-action' => 'keydown.esc->menu#close', 'data-phem-state-value' => 'forged', 'className' => ['a', '', 'b'], 'hidden' => true, 'x" onclick="y' => 1, 'onClick' => 'f', 'style' => ['color' => 'red']], $state, ''),
            'the application adds attributes, but it cannot change the state or add a handler',
        );
        self::assertStringStartsWith('<main id="app" ', RootElement::render(['tag' => 'main', 'id' => 'app'], $state, ''));

        $this->expectException(\InvalidArgumentException::class);
        RootElement::render(['tag' => 'div onclick=x'], $state, '');
    }
}
