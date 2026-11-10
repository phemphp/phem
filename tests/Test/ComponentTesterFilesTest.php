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

namespace Phem\Tests\Test;

use Phem\Test\ComponentTester;
use Phem\Test\Internal\Driver;
use Phem\Test\Internal\EventResult;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The files of the file inputs in a ComponentTester belong to the element, as in a browser: a
 * morph keeps them while it keeps the element, and the other renders drop them.
 */
final class ComponentTesterFilesTest extends TestCase
{
    private const PAGE = <<<'HTML'
        <div data-phem-state-value="{}" data-phem-stream-value="/hub?match=t">
            <div data-phem-c="c1" id="first"><input type="file" name="photo" /></div>
            <div data-phem-c="c2" id="second">
                <form id="f" data-phem-on-submit="c2.save"><input type="file" name="photo" /></form>
            </div>
        </div>
        HTML;

    public function testMorphKeepsTheFilesOfAnInputWhenAnInputBeforeItGoesAway(): void
    {
        // The first component renders no input: the input of the form is now the first input
        // named "photo" of the page, and it keeps its files.
        $driver = self::driver([EventResult::updates(['c1' => '<div data-phem-c="c1" id="first"></div>'])]);
        $tester = new ComponentTester($driver);
        $tester->upload('#second input', __FILE__)->submit('#f')->submit('#f');

        self::assertSame([[['photo', __FILE__]], [['photo', __FILE__]]], $driver->files);
    }

    public function testDropAndPasteSendTheirFilesAsTheFilesOfTheEvent(): void
    {
        $page = <<<'HTML'
            <div data-phem-state-value="{}">
                <div data-phem-c="c1"><div id="zone" data-phem-on-drop="c1.add"><textarea data-phem-on-paste="c1.add"></textarea></div></div>
            </div>
            HTML;

        $driver = self::driver([], $page);
        new ComponentTester($driver)->drop('#zone', [__FILE__, __FILE__])->paste('textarea', __FILE__)->drop('textarea');

        self::assertSame([[['_phem_files[]', __FILE__], ['_phem_files[]', __FILE__]], [['_phem_files[]', __FILE__]], []], $driver->files);
    }

    public function testScrollGoesOnlyToTheElementThatScrolls(): void
    {
        $page = <<<'HTML'
            <div data-phem-state-value="{}">
                <div data-phem-c="c1"><div id="list" data-phem-on-scroll="c1.more"><pre id="inner">x</pre></div></div>
            </div>
            HTML;

        $driver = self::driver([], $page);
        $tester = new ComponentTester($driver);
        $tester->fire('#list', 'scroll');
        self::assertCount(1, $driver->files);

        try {
            $tester->fire('#inner', 'scroll');
            self::fail('A scroll of the inner element does not go to the handler of the list.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('has no handler for the "scroll" event', $e->getMessage());
        }
    }

    public function testPushThatIsNotAMorphDropsTheFiles(): void
    {
        foreach (['update' => [], 'update" method="morph' => [['photo', __FILE__]]] as $action => $files) {
            $driver = self::driver();
            $updates = [];
            $tester = new ComponentTester($driver, updates: static function () use (&$updates): array {
                return $updates;
            });
            $tester->upload('#second input', __FILE__);

            $updates[] = ['topics' => ['t'], 'data' => '<turbo-stream action="'.$action.'" target="second"><template><form id="f" data-phem-on-submit="c2.save"><input type="file" name="photo" /></form></template></turbo-stream>'];
            $tester->receive()->submit('#f');

            self::assertSame([$files], $driver->files, $action);
        }
    }

    public function testMorphKeepsTheFilesOfAKeptComponentAndOfAnIgnoredElement(): void
    {
        $page = <<<'HTML'
            <div data-phem-state-value="{}">
                <form data-phem-c="c1" data-phem-on-submit="c1.save">
                    <div data-phem-c="c2"><input type="file" name="photo" /></div>
                    <div id="widget" data-phem-ignore><input type="file" name="photo" /></div>
                    <input type="file" name="cover" />
                </form>
            </div>
            HTML;

        // The server does not render c2 again, the widget belongs to JS, and the cover input
        // gets an id: the morph keeps the three elements, and their files.
        $form = '<form data-phem-c="c1" data-phem-on-submit="c1.save"><template data-phem-keep="c2"></template><div id="widget" data-phem-ignore><input type="file" name="photo" /></div><input type="file" name="cover" id="cover" /></form>';
        $driver = self::driver([EventResult::updates(['c1' => $form])], $page);
        $tester = new ComponentTester($driver);
        $tester->upload('[data-phem-c=c2] input', __FILE__)->upload('#widget input', __DIR__.'/InteractsWithPhemTest.php')->upload('[name=cover]', __FILE__)->submit()->submit();

        $files = [['photo', __FILE__], ['photo', __DIR__.'/InteractsWithPhemTest.php'], ['cover', __FILE__]];
        self::assertSame([$files, $files], $driver->files);
    }

    /**
     * A driver that renders the page, answers each event with the next answer, and keeps the
     * files of each event.
     *
     * @param list<EventResult> $answers
     *
     * @return Driver&object{files: list<list<array{string, string}>>}
     */
    private static function driver(array $answers = [], string $html = self::PAGE): Driver
    {
        return new class($html, $answers) implements Driver {
            /** @var list<list<array{string, string}>> */
            public array $files = [];

            /** @param list<EventResult> $answers */
            public function __construct(
                private readonly string $html,
                private array $answers,
            ) {
            }

            public function html(): string
            {
                return $this->html;
            }

            public function isDocument(): bool
            {
                return false;
            }

            public function dispatch(array $handlers, array $event, array $bound = [], array $stale = [], ?array $item = null, array $feeds = []): EventResult
            {
                $this->files[] = $event['files'] ?? [];

                return array_shift($this->answers) ?? EventResult::updates([]);
            }

            public function state(?string $fiber = null): array
            {
                return [];
            }

            public function url(): string
            {
                return '/';
            }

            public function status(): int
            {
                return 200;
            }

            public function redirect(): ?string
            {
                return null;
            }
        };
    }
}
