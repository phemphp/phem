<?php

declare(strict_types=1);

namespace Mamu\Tests\Runtime;

use Mamu\Http\PageView;
use Mamu\Http\Title;
use Mamu\Http\TurboStreams;
use Mamu\Rendering\Fiber;
use Mamu\State\PageState;
use PHPUnit\Framework\TestCase;
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
        $fiber = new Fiber('mamu1', '/A#0', 'A');
        $fiber->html = '<title>T</title><b>x</b>';

        self::assertSame('<turbo-stream action="update" method="morph" target="mamu1"><template><b>x</b></template></turbo-stream>', new TurboStreams()->updates([$fiber]));
    }

    public function testPageStateKeepsTheRouteValuesInThePathKey(): void
    {
        $state = new PageState(['mamu1' => ['n' => 1]], ['id' => '7']);

        self::assertSame(['_path' => ['id' => '7'], 'mamu1' => ['n' => 1]], $state->toArray());
        self::assertEquals($state, PageState::fromArray($state->toArray()));
        self::assertSame(['mamu1' => ['n' => 1]], new PageState(['mamu1' => ['n' => 1]])->toArray());
        self::assertSame([], PageState::fromArray(['_path' => 'not an array'])->values);
    }

    public function testPageViewRendersTheRootElement(): void
    {
        $page = new PageView('App\\Pages\\Home', null, new Markup('<p>x</p>', 'UTF-8'), '{"a":"<b>"}', 'sig');
        $actions = 'click->mamu#dispatch dblclick->mamu#dispatch input->mamu#dispatch change->mamu#dispatch submit->mamu#dispatch keydown->mamu#dispatch keyup->mamu#dispatch focusin->mamu#dispatch focusout->mamu#dispatch';
        $actions = htmlspecialchars($actions);

        self::assertSame('<div id="mamu-root" data-controller="mamu" data-action="'.$actions.'" data-mamu-state-value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}" data-mamu-signature-value="sig"><p>x</p></div>', (string) $page->root());
        self::assertSame(
            'data-controller="mamu menu" data-action="'.$actions.' keydown.esc-&gt;menu#close" data-mamu-state-value="{&quot;a&quot;:&quot;&lt;b&gt;&quot;}" data-mamu-signature-value="sig" class="a b" hidden',
            (string) $page->attributes(['id' => null, 'data-controller' => 'menu', 'data-action' => 'keydown.esc->menu#close', 'data-mamu-state-value' => 'forged', 'class' => ['a', '', 'b'], 'hidden' => true, 'x" onclick="y' => 1]),
            'the application adds attributes, but it cannot change the state',
        );
        self::assertStringStartsWith('<main id="app" ', (string) $page->root(['id' => 'app'], 'main'));

        $this->expectException(\InvalidArgumentException::class);
        $page->root([], 'div onclick=x');
    }
}
