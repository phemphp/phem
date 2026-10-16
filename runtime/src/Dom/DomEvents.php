<?php

declare(strict_types=1);

namespace Rephp\Dom;

/**
 * The DOM events of the event handlers (`onClick={{ f }}`).
 */
final class DomEvents
{
    /** The DOM events that the `rhp` Stimulus controller listens to. */
    public const LISTENED = ['click', 'dblclick', 'input', 'change', 'submit', 'keydown', 'keyup', 'focusin', 'focusout'];

    /** React event names → DOM event names. */
    private const ALIASES = ['doubleclick' => 'dblclick', 'focus' => 'focusin', 'blur' => 'focusout'];

    /** The DOM event name of a React event name (`doubleclick` → `dblclick`). */
    public static function name(string $event): string
    {
        return self::ALIASES[$event] ?? $event;
    }

    /** The `data-action` value of the page root: `click->rhp#dispatch dblclick->rhp#dispatch …`. */
    public static function stimulusActions(): string
    {
        return implode(' ', array_map(static fn (string $a): string => $a.'->rhp#dispatch', self::LISTENED));
    }
}
