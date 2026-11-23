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
    /**
     * The DOM events that the `phem` Stimulus controller listens to. `phem:call` is the event that
     * the JS code dispatches to call an `onCall` handler (`data-phem-on-call`).
     */
    public const LISTENED = [
        'click', 'dblclick', 'input', 'change', 'submit', 'keydown', 'keyup', 'focusin', 'focusout',
        'dragstart', 'dragover', 'drop', 'paste', 'contextmenu', 'pointerdown', 'pointerup', 'scroll',
        'phem:call',
    ];

    /**
     * The DOM events that the controller listens to in the capture phase: a scroll does not
     * bubble, and a `phem:call` of the JS code can have no `bubbles: true`.
     */
    private const CAPTURED = ['scroll', 'phem:call'];

    /**
     * The DOM events that the browser never sends to a disabled form control or to an inert
     * element: a user cannot click it, focus it or type in it. A disabled control still gets the
     * pointer events, a `focusout` when it becomes disabled, and the events of the JS code: the
     * controllers send `input` and `change` to the fields whose value they set, and `phem:call`.
     */
    private const NOT_SENT_TO_DISABLED = ['click', 'keydown', 'keyup', 'focusin', 'paste'];

    /** React event names → DOM event names. */
    private const ALIASES = ['doubleclick' => 'dblclick', 'focus' => 'focusin', 'blur' => 'focusout'];

    /** The DOM event name of a React event name (`doubleclick` → `dblclick`). */
    public static function name(string $event): string
    {
        return self::ALIASES[$event] ?? $event;
    }

    /** Whether the browser can send this DOM event to a disabled form control or an inert element. */
    public static function sentToDisabled(string $event): bool
    {
        return !\in_array($event, self::NOT_SENT_TO_DISABLED, true);
    }

    /** The `data-action` value of the page root: `click->phem#dispatch … scroll->phem#dispatch:capture`. */
    public static function stimulusActions(): string
    {
        return implode(' ', array_map(static fn (string $a): string => $a.'->phem#dispatch'.(\in_array($a, self::CAPTURED, true) ? ':capture' : ''), self::LISTENED));
    }
}
