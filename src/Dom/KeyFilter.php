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

namespace Phem\Dom;

/**
 * The keys of `phem:key="Enter"` (only they send the `onKeyDown` or `onKeyUp` event of the
 * element) and of `phem:shortcut="mod+k"`. The keys are separated by spaces, each with its
 * modifiers: `Enter`, `ctrl+s`, `shift+Enter`, `mod+k`, `Space`. A key is the `key` of the browser
 * event: one character, or a name such as `Enter`, `Escape` or `ArrowUp`. The key `+` is `+` or
 * `ctrl++`. The names have no case.
 *
 * `mod` is the Command key (`meta`) on a Mac, and `ctrl` on the other systems. It does not go
 * with `ctrl` or `meta` in one key. Here, with no system, `mod` matches `ctrl` or `meta`.
 *
 * The modifiers must match, as in the `phem` controller: `Enter` does not match Shift+Enter.
 * Shift is free for a character that is not a letter, such as `?`, which needs Shift on some
 * keyboards.
 */
final class KeyFilter
{
    public const MODIFIERS = ['ctrl', 'shift', 'alt', 'meta'];

    /** The modifiers that the keys can have: also `mod`. */
    private const KEY_MODIFIERS = [...self::MODIFIERS, 'mod'];

    /**
     * @param list<array{key: string, modifiers: list<string>}> $combos
     */
    private function __construct(
        public readonly string $keys,
        private readonly array $combos,
    ) {
    }

    /**
     * @param string $directive the directive of the keys, for the messages
     *
     * @throws \InvalidArgumentException when the keys are not valid
     */
    public static function parse(string $keys, string $directive = 'phem:key'): self
    {
        if ('' === trim($keys)) {
            throw new \InvalidArgumentException(\sprintf('"%s" takes the keys, for example "Enter" or "mod+s Escape", an empty string given.', $directive));
        }

        $combos = [];
        foreach (preg_split('/\s+/', trim($keys)) ?: [] as $combo) {
            $combos[] = self::combo($combo) ?? throw new \InvalidArgumentException(\sprintf('"%s" has an invalid key "%s": write the "key" of the browser event, with "ctrl+", "shift+", "alt+", "meta+" or "mod+" (not with "ctrl+" or "meta+") before it.', $directive, $combo));
        }

        return new self($keys, $combos);
    }

    /**
     * Whether a key event matches: its `key` and the modifiers that it has.
     *
     * @param list<string> $modifiers the modifiers of the event (`ctrl`, `shift`, `alt`, `meta`,
     *                             or `mod` for `ctrl`)
     */
    public function matches(string $key, array $modifiers = []): bool
    {
        $key = mb_strtolower($key);
        $modifiers = array_map(static fn (string $m): string => 'mod' === strtolower($m) ? 'ctrl' : strtolower($m), $modifiers);

        // `mod` is the Command key when the event has it and no Ctrl, else Ctrl.
        $mod = \in_array('meta', $modifiers, true) && !\in_array('ctrl', $modifiers, true) ? 'meta' : 'ctrl';

        foreach ($this->combos as $combo) {
            if ($combo['key'] !== $key) {
                continue;
            }

            $wanted = array_map(static fn (string $m): string => 'mod' === $m ? $mod : $m, $combo['modifiers']);
            $freeShift = 1 === mb_strlen($key) && mb_strtolower($key) === mb_strtoupper($key) && !\in_array('shift', $wanted, true);
            $matches = true;
            foreach (self::MODIFIERS as $m) {
                if (!('shift' === $m && $freeShift) && \in_array($m, $wanted, true) !== \in_array($m, $modifiers, true)) {
                    $matches = false;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{key: string, modifiers: list<string>}|null
     */
    private static function combo(string $combo): ?array
    {
        if (str_ends_with($combo, '++')) {
            [$modifiers, $key] = [substr($combo, 0, -2), '+'];
        } elseif ('+' === $combo) {
            [$modifiers, $key] = ['', '+'];
        } else {
            $at = strrpos($combo, '+');
            [$modifiers, $key] = false === $at ? ['', $combo] : [substr($combo, 0, $at), substr($combo, $at + 1)];
        }

        // One character, or a name such as `Enter` or `ArrowUp`.
        if ('' === $key || (mb_strlen($key) > 1 && 1 !== preg_match('/\A[a-zA-Z0-9]+\z/', $key))) {
            return null;
        }

        $modifiers = '' === $modifiers ? [] : array_map(strtolower(...), explode('+', $modifiers));
        if ([] !== array_diff($modifiers, self::KEY_MODIFIERS)) {
            return null;
        }

        // `mod` is `ctrl` or `meta`: with one of them, the key is not the same on each system.
        if (\in_array('mod', $modifiers, true) && [] !== array_intersect($modifiers, ['ctrl', 'meta'])) {
            return null;
        }

        $key = mb_strtolower($key);

        return ['key' => 'space' === $key ? ' ' : $key, 'modifiers' => $modifiers];
    }
}
