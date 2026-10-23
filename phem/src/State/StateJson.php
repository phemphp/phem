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

namespace Phem\State;

/**
 * The JSON text of a page state: a JSON object with one entry on each line, in the order of the
 * keys (see PageState for the keys).
 *
 *     {
 *     "_tree":"…",
 *     "phem0123456789ab":{"count":1}
 *     }
 *
 * An event response sends only the entries that changed (see changes()). The browser splits its
 * text into entries, sets the changes, and joins the entries again (see apply()): it never
 * encodes a value again, so the text that it sends back is the text that the server signed.
 * json_encode() writes no line break in a value (it escapes it in a string), so each line break
 * is between two entries.
 */
final class StateJson
{
    private const FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    /** The characters of a key: the browser reads a key with no JSON decoder, and sorts the keys as PHP does. */
    private const KEY = '/^[A-Za-z0-9_]+$/D';

    /** @param array<array-key, mixed> $state */
    public static function encode(array $state): string
    {
        $entries = [];
        foreach ($state as $key => $value) {
            $entries[(string) $key] = json_encode($value, self::FLAGS);
        }

        return self::join($entries);
    }

    /**
     * The entries of a text of encode(): key => the JSON text of its value. Null for a text with
     * another form.
     *
     * @return array<string, string>|null
     */
    public static function entries(string $json): ?array
    {
        if ('{}' === $json) {
            return [];
        }

        if (!str_starts_with($json, "{\n") || !str_ends_with($json, "\n}")) {
            return null;
        }

        $entries = [];
        foreach (explode(",\n", substr($json, 2, -2)) as $line) {
            $end = strpos($line, '"', 1);
            if (!str_starts_with($line, '"') || false === $end || ':' !== ($line[$end + 1] ?? '')) {
                return null;
            }

            $entries[substr($line, 1, $end - 1)] = substr($line, $end + 2);
        }

        return $entries;
    }

    /**
     * The entries of a new state that are not the same in the state before it: key => the JSON
     * text of its value, or null for an entry that the new state does not have. A state before
     * with another form counts as empty, as in the browser: the changes have all the entries.
     *
     * @param string $after a text of encode()
     *
     * @return array<string, string|null>
     */
    public static function changes(string $before, string $after): array
    {
        $old = self::entries($before) ?? [];
        $new = self::entries($after) ?? throw new \InvalidArgumentException('The new state is not a text of StateJson::encode().');
        $changes = [];

        foreach ($new as $key => $value) {
            if (($old[$key] ?? null) !== $value) {
                $changes[$key] = $value;
            }
        }

        foreach (array_diff_key($old, $new) as $key => $_) {
            $changes[$key] = null;
        }

        return $changes;
    }

    /**
     * The text of a state after the changes, as the browser makes it (see changes()).
     *
     * @param array<array-key, string|null> $changes
     */
    public static function apply(string $json, array $changes): string
    {
        $entries = self::entries($json) ?? [];

        foreach ($changes as $key => $value) {
            if (null === $value) {
                unset($entries[$key]);
            } else {
                $entries[(string) $key] = $value;
            }
        }

        return self::join($entries);
    }

    /** @param array<array-key, string> $entries key => JSON text */
    private static function join(array $entries): string
    {
        if ([] === $entries) {
            return '{}';
        }

        ksort($entries, \SORT_STRING);

        $lines = [];
        foreach ($entries as $key => $value) {
            if (1 !== preg_match(self::KEY, (string) $key)) {
                throw new \LogicException(\sprintf('The key "%s" of a page state can have only letters, digits and "_".', $key));
            }

            $lines[] = '"'.$key.'":'.$value;
        }

        return "{\n".implode(",\n", $lines)."\n}";
    }
}
