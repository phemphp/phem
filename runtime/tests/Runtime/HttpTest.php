<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Http\PageView;
use Rephp\Http\Title;
use Rephp\Http\TurboStreams;
use Rephp\Rendering\Fiber;
use Rephp\State\PageState;
use Twig\Markup;

final class HttpTest extends TestCase
{
    public function testTitleGoesOutOfThePage(): void
    {
        self::assertSame(['Hi', '<p>a</p>'], Title::extract("<title>\n Hi </title><p>a</p>"));
        self::assertSame([null, '<p>a</p>'], Title::extract('<p>a</p>'));
        self::assertSame('<p>a</p><title>B</title>', Title::strip('<title>A</title><p>a</p><title>B</title>'));
    }

    public function testTurboStreamsMorphTheFibers(): void
    {
        $fiber = new Fiber('rh1', '/A#0', 'A');
        $fiber->html = '<title>T</title><b>x</b>';

        self::assertSame('<turbo-stream action="update" method="morph" target="rh1"><template><b>x</b></template></turbo-stream>', new TurboStreams()->updates([$fiber]));
    }

    public function testPageStateKeepsTheRouteValuesInThePathKey(): void
    {
        $state = new PageState(['rh1' => ['n' => 1]], ['id' => '7']);

        self::assertSame(['_path' => ['id' => '7'], 'rh1' => ['n' => 1]], $state->toArray());
        self::assertEquals($state, PageState::fromArray($state->toArray()));
        self::assertSame(['rh1' => ['n' => 1]], new PageState(['rh1' => ['n' => 1]])->toArray());
        self::assertSame([], PageState::fromArray(['_path' => 'not an array'])->values);
    }

    public function testPageViewRendersTheRootElement(): void
    {
        $page = new PageView('App\\Pages\\Home', null, new Markup('<p>x</p>', 'UTF-8'), '{"a":"<b>"}', 'sig');
        $actions = 'click->rhp#dispatch dblclick->rhp#dispatch input->rhp#dispatch change->rhp#dispatch submit->rhp#dispatch keydown->rhp#dispatch keyup->rhp#dispatch focusin->rhp#dispatch focusout->rhp#dispatch';
        $actions = htmlspecialchars($actions);

        self::assertSame('<div id="rh-root" data-controller="rhp" data-action="'.$actions.'" data-rhp-state-value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}" data-rhp-signature-value="sig"><p>x</p></div>', (string) $page->root());
        self::assertSame(
            'data-controller="rhp menu" data-action="'.$actions.' keydown.esc-&gt;menu#close" data-rhp-state-value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}" data-rhp-signature-value="sig" class="a b" hidden',
            (string) $page->attributes(['id' => null, 'data-controller' => 'menu', 'data-action' => 'keydown.esc->menu#close', 'data-rhp-state-value' => 'forged', 'class' => ['a', '', 'b'], 'hidden' => true, 'x" onclick="y' => 1]),
            'the application adds attributes, but it cannot change the state',
        );
        self::assertStringStartsWith('<main id="app" ', (string) $page->root(['id' => 'app'], 'main'));

        $this->expectException(\InvalidArgumentException::class);
        $page->root([], 'div onclick=x');
    }
}
