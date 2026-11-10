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
 * The keys of `phem:key="Enter"`: only they send the `onKeyDown` or `onKeyUp` event of the
 * element. The keys are separated by spaces, each with its modifiers: `Enter`, `ctrl+s`,
 * `shift+Enter`, `Space`. A key is the `key` of the browser event: one character, or a name such
 * as `Enter`, `Escape` or `ArrowUp`. The key `+` is `+` or `ctrl++`. The names have no case.
 *
 * The modifiers must match, as in the `phem` controller: `Enter` does not match Shift+Enter.
 * Shift is free for a character that is not a letter, such as `?`, which needs Shift on some
 * keyboards.
 */
final class KeyFilter
{
    public const MODIFIERS = ['ctrl', 'shift', 'alt', 'meta'];

    /**
     * @param list<array{key: string, modifiers: list<string>}> $combos
     */
    private function __construct(
        public readonly string $keys,
        private readonly array $combos,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the keys are not valid
     */
    public static function parse(string $keys): self
    {
        if ('' === trim($keys)) {
            throw new \InvalidArgumentException('"phem:key" takes the keys, for example "Enter" or "ctrl+s Escape", an empty string given.');
        }

        $combos = [];
        foreach (preg_split('/\s+/', trim($keys)) ?: [] as $combo) {
            $combos[] = self::combo($combo) ?? throw new \InvalidArgumentException(\sprintf('"phem:key" has an invalid key "%s": write the "key" of the browser event, with "ctrl+", "shift+", "alt+" or "meta+" before it.', $combo));
        }

        return new self($keys, $combos);
    }

    /**
     * Whether a key event matches: its `key` and the modifiers that it has.
     *
     * @param list<string> $modifiers the modifiers of the event (`ctrl`, `shift`, `alt`, `meta`)
     */
    public function matches(string $key, array $modifiers = []): bool
    {
        $key = mb_strtolower($key);
        $modifiers = array_map(strtolower(...), $modifiers);

        foreach ($this->combos as $combo) {
            if ($combo['key'] !== $key) {
                continue;
            }

            $freeShift = 1 === mb_strlen($key) && mb_strtolower($key) === mb_strtoupper($key) && !\in_array('shift', $combo['modifiers'], true);
            $matches = true;
            foreach (self::MODIFIERS as $m) {
                if (!('shift' === $m && $freeShift) && \in_array($m, $combo['modifiers'], true) !== \in_array($m, $modifiers, true)) {
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
        if ([] !== array_diff($modifiers, self::MODIFIERS)) {
            return null;
        }

        $key = mb_strtolower($key);

        return ['key' => 'space' === $key ? ' ' : $key, 'modifiers' => $modifiers];
    }
}
