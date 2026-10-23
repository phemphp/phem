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
use PHPUnit\Framework\TestCase;

/**
 * The JSON text of a page state, and the changes that an event response sends (see StateJson).
 */
final class StateJsonTest extends TestCase
{
    /**
     * The same vector as in the tests of the phem controller (assets/tests/phem_controller.test.js):
     * the controller makes the same text from the same state and changes. A value has an escaped
     * line break after a comma and escaped quotes: they are not the end of an entry.
     */
    private const VECTOR = <<<'JSON'
        {"before":"{\n\"_path\":{\"id\":\"3\"},\n\"_tree\":\"AAAAAAAA0\",\n\"phem0a\":{\"text\":\"a,\\nb \\\"c\\\" é/\"},\n\"phem0b\":{\"n\":1.0}\n}","changes":{"phem0a":null,"phem0b":"{\"n\":2.5}","phem0c":"[]","_tree":"\"BBBBBBBB0,CCCCCCCC1\""},"after":"{\n\"_path\":{\"id\":\"3\"},\n\"_tree\":\"BBBBBBBB0,CCCCCCCC1\",\n\"phem0b\":{\"n\":2.5},\n\"phem0c\":[]\n}"}
        JSON;

    public function testEncodesOneEntryOnEachLineInTheOrderOfTheKeys(): void
    {
        $state = ['phem0b' => ['n' => 1.0], '_tree' => 'AAAAAAAA0', 'phem0a' => ['text' => "a,\nb \"c\" é/"], '_path' => ['id' => '3']];

        $json = StateJson::encode($state);

        self::assertSame(self::vector()['before'], $json);
        ksort($state);
        self::assertSame($state, json_decode($json, true), 'a JSON object');
        self::assertSame('{}', StateJson::encode([]));
    }

    public function testEntriesAreTheJsonTextsOfTheValues(): void
    {
        self::assertSame(['_path' => '{"id":"3"}', '_tree' => '"AAAAAAAA0"', 'phem0a' => '{"text":"a,\nb \"c\" é/"}', 'phem0b' => '{"n":1.0}'], StateJson::entries(self::vector()['before']));
        self::assertSame([], StateJson::entries('{}'));
        self::assertNull(StateJson::entries('{"phem0a":{"n":1}}'), 'another form');
        self::assertNull(StateJson::entries(''));
        self::assertNull(StateJson::entries("{\nphem0a:1\n}"));
    }

    public function testChangesHaveOnlyTheEntriesThatChanged(): void
    {
        ['before' => $before, 'changes' => $changes, 'after' => $after] = self::vector();

        $actual = StateJson::changes($before, $after);

        self::assertEquals($changes, $actual, 'a new value, a new entry, and null for a removed entry');
        self::assertArrayNotHasKey('_path', $actual, 'the same value');
        self::assertSame([], StateJson::changes($after, $after));
    }

    public function testAStateBeforeWithAnotherFormGetsAllTheEntries(): void
    {
        $after = self::vector()['after'];

        self::assertSame(StateJson::entries($after), StateJson::changes('{"phem0a":{"n":1}}', $after));
        self::assertSame($after, StateJson::apply('{"phem0a":{"n":1}}', StateJson::changes('{"phem0a":{"n":1}}', $after)), 'the browser also starts with no entries');
    }

    public function testTheBrowserMakesTheTextOfTheServerFromTheChanges(): void
    {
        ['before' => $before, 'changes' => $changes, 'after' => $after] = self::vector();

        self::assertSame($after, StateJson::apply($before, $changes));
        self::assertSame($after, StateJson::apply($before, StateJson::changes($before, $after)));
        self::assertSame('{}', StateJson::apply($after, array_fill_keys(array_keys((array) StateJson::entries($after)), null)), 'no entry');
        self::assertSame(['_path' => ['id' => '3'], '_tree' => 'BBBBBBBB0,CCCCCCCC1', 'phem0b' => ['n' => 2.5], 'phem0c' => []], json_decode($after, true));
    }

    public function testAKeyHasOnlyLettersDigitsAndUnderscores(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The key "a"b" of a page state can have only letters, digits and "_".');

        StateJson::encode(['a"b' => 1]);
    }

    public function testANewStateMustHaveTheFormOfEncode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StateJson::changes('{}', '{"phem0a":1}');
    }

    /** @return array{before: string, changes: array<string, string|null>, after: string} */
    private static function vector(): array
    {
        return json_decode(self::VECTOR, true, 512, \JSON_THROW_ON_ERROR);
    }
}
