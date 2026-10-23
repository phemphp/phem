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
 * The DOM events of the event handlers (`onClick={{ f }}`).
 */
final class DomEvents
{
    /** The DOM events that the `phem` Stimulus controller listens to. */
    public const LISTENED = ['click', 'dblclick', 'input', 'change', 'submit', 'keydown', 'keyup', 'focusin', 'focusout'];

    /** React event names → DOM event names. */
    private const ALIASES = ['doubleclick' => 'dblclick', 'focus' => 'focusin', 'blur' => 'focusout'];

    /** The DOM event name of a React event name (`doubleclick` → `dblclick`). */
    public static function name(string $event): string
    {
        return self::ALIASES[$event] ?? $event;
    }

    /** The `data-action` value of the page root: `click->phem#dispatch dblclick->phem#dispatch …`. */
    public static function stimulusActions(): string
    {
        return implode(' ', array_map(static fn (string $a): string => $a.'->phem#dispatch', self::LISTENED));
    }
}
