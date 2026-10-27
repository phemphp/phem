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

use Phem\Rendering\KeptRuns;
use Phem\State\TreeShape;
use PHPUnit\Framework\TestCase;

/**
 * One placeholder for a run of kept fibers (see KeptRuns).
 */
final class KeptRunsTest extends TestCase
{
    private const PAGE = 'phem000000000000';

    public function testARunOfNextChildrenHasOnePlaceholder(): void
    {
        $html = '<ul>'.self::keep(1).self::keep(2).self::keep(3).'</ul>';

        $collapsed = new KeptRuns()->collapse($html, self::shape());

        self::assertSame('<ul><template data-phem-keep="phem000000000001..phem000000000003"></template></ul>', $collapsed);
        self::assertSame($html, new KeptRuns()->expand($collapsed, self::shape()));
    }

    public function testAFiberThatIsNotTheNextChildStartsANewRun(): void
    {
        // Row 3 renders again, and the parent moved row 4 after row 6.
        $html = '<ul>'.self::keep(1).self::keep(2).'<li>3</li>'.self::keep(5).self::keep(6).self::keep(4).'</ul>';

        $collapsed = new KeptRuns()->collapse($html, self::shape());

        self::assertSame('<ul><template data-phem-keep="phem000000000001..phem000000000002"></template><li>3</li><template data-phem-keep="phem000000000005..phem000000000006"></template>'.self::keep(4).'</ul>', $collapsed);
        self::assertSame($html, new KeptRuns()->expand($collapsed, self::shape()));
    }

    public function testPlaceholdersWithTextBetweenThemStayApart(): void
    {
        $html = self::keep(1).' '.self::keep(2)."\n".self::keep(3);

        self::assertSame($html, new KeptRuns()->collapse($html, self::shape()), 'the morph keeps the text between the elements');
    }

    public function testAFiberThatTheShapeDoesNotKnowStaysApart(): void
    {
        $html = self::keep(1).self::keep(2).self::keep(9);

        self::assertSame('<template data-phem-keep="phem000000000001..phem000000000002"></template>'.self::keep(9), new KeptRuns()->collapse($html, self::shape()));
    }

    public function testARunThatTheShapeDoesNotHaveCannotExpand(): void
    {
        $runs = new KeptRuns();

        self::assertNull($runs->expand('<template data-phem-keep="phem000000000003..phem000000000001"></template>', self::shape()), 'the last fiber comes before the first one');
        self::assertNull($runs->expand('<template data-phem-keep="phem000000000009..phem000000000001"></template>', self::shape()), 'an unknown fiber');
        self::assertNull($runs->expand('<template data-phem-keep="phem000000000001..phem000000000003"></template>', new TreeShape()), 'no shape');
    }

    private static function keep(int $row): string
    {
        return '<template data-phem-keep="'.self::id($row).'"></template>';
    }

    private static function id(int $row): string
    {
        return \sprintf('phem%012x', $row);
    }

    /** The page with 6 rows. */
    private static function shape(): TreeShape
    {
        $fibers = [self::PAGE => [null, null]];
        for ($row = 1; $row <= 6; ++$row) {
            $fibers[self::id($row)] = [self::PAGE, TreeShape::hash((string) $row)];
        }

        return new TreeShape($fibers);
    }
}
