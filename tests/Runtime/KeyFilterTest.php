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

use Phem\Dom\KeyFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyFilterTest extends TestCase
{
    /**
     * @param list<string> $modifiers
     */
    #[DataProvider('keys')]
    public function testMatchesTheKeyAndItsModifiers(string $filter, string $key, array $modifiers, bool $matches): void
    {
        self::assertSame($matches, KeyFilter::parse($filter)->matches($key, $modifiers));
    }

    /** @return iterable<string, array{string, string, list<string>, bool}> */
    public static function keys(): iterable
    {
        yield 'the key' => ['Enter', 'Enter', [], true];
        yield 'no case' => ['enter', 'Enter', [], true];
        yield 'another key' => ['Enter', 'Escape', [], false];
        yield 'one of the keys' => ['Enter Escape', 'Escape', [], true];
        yield 'a modifier' => ['ctrl+Enter', 'Enter', ['ctrl'], true];
        yield 'no modifier' => ['ctrl+Enter', 'Enter', [], false];
        yield 'an extra modifier' => ['Enter', 'Enter', ['shift'], false];
        yield 'two modifiers' => ['ctrl+shift+K', 'k', ['shift', 'ctrl'], true];
        yield 'space' => ['Space', ' ', [], true];
        yield 'shift is free for a symbol' => ['?', '?', ['shift'], true];
        yield 'shift is not free for a letter' => ['k', 'K', ['shift'], false];
        yield 'the plus key' => ['+', '+', [], true];
        yield 'the plus key with a modifier' => ['ctrl++', '+', ['ctrl'], true];
    }

    #[DataProvider('invalid')]
    public function testRefusesInvalidKeys(string $filter): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyFilter::parse($filter);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => ['  '];
        yield 'an unknown modifier' => ['cmd+s'];
        yield 'no key' => ['ctrl+'];
        yield 'an empty modifier' => ['ctrl++s'];
        yield 'a name with other characters' => ['Arrow-Up'];
    }
}
