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

use Phem\State\TextPatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The patch of a JSON text in the parts between its commas (see TextPatch).
 */
final class TextPatchTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<list<int|string>>}> */
    public static function patches(): iterable
    {
        yield 'the same text' => ['"A0,B1"', '"A0,B1"', []];
        yield 'other parts: a splice for each run' => ['"A0,B1,C1,D1,E1"', '"A9,B1,X1,D1,E9"', [[0, 1, '"A9'], [2, 1, 'X1'], [4, 1, 'E9"']]];
        yield 'a part more' => ['"A0,B1,C1"', '"A0,B1,N1,C1"', [[2, 0, 'N1']]];
        yield 'a part less' => ['"A0,B1,N1,C1"', '"A0,B1,C1"', [[2, 1]]];
        yield 'a part more at the end' => ['"A0,B1"', '"A0,B1,C1"', [[1, 1, 'B1', 'C1"']]];
        yield 'one part' => ['"A0,B1"', '"A0"', [[0, 2, '"A0"']]];
    }

    /** @param list<list<int|string>> $patch */
    #[DataProvider('patches')]
    public function testThePatchMakesTheNewText(string $old, string $new, array $patch): void
    {
        self::assertSame($patch, TextPatch::diff($old, $new));
        self::assertSame($new, TextPatch::apply($old, $patch));
    }

    /** @param array<array-key, mixed> $patch */
    #[DataProvider('invalidPatches')]
    public function testAPatchWithAnotherFormIsNotValid(array $patch): void
    {
        self::assertNull(TextPatch::apply('"A0,B1,C1"', $patch));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidPatches(): iterable
    {
        yield 'not a splice' => [['a']];
        yield 'no count' => [[[0]]];
        yield 'after the end' => [[[2, 2, 'x']]];
        yield 'a negative count' => [[[1, -1]]];
        yield 'a part that is not a string' => [[[0, 1, 1]]];
        yield 'not in the order of the indexes' => [[[2, 1, 'x'], [0, 1, 'y']]];
        yield 'an overlap' => [[[0, 2, 'x'], [1, 1, 'y']]];
    }
}
