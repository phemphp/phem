<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Http\Title;
use Rephp\Http\TurboStreams;
use Rephp\Rendering\Fiber;
use Rephp\State\PageState;

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
}
