<?php

declare(strict_types=1);

namespace Phem\Ui\Base;

/**
 * The classes that several components share, copied from the Tailwind demos of Base UI.
 *
 * The values are full class names in the source, so the Tailwind build of the app finds them.
 */
final class Style
{
    /** A button, and the trigger of a dialog, a popover, a menu… */
    public const string BUTTON = 'flex h-8 items-center justify-center gap-2 rounded-none border border-neutral-950 dark:border-white bg-white dark:bg-neutral-950 px-3 text-sm leading-none whitespace-nowrap font-normal text-neutral-950 dark:text-white select-none hover:not-data-disabled:bg-neutral-100 dark:hover:not-data-disabled:bg-neutral-800 active:not-data-disabled:bg-neutral-200 dark:active:not-data-disabled:bg-neutral-700 focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-neutral-950 dark:focus-visible:outline-white data-disabled:border-neutral-500 data-disabled:text-neutral-500 disabled:border-neutral-500 disabled:text-neutral-500 dark:data-disabled:border-neutral-400 dark:data-disabled:text-neutral-400';

    /** A popup: the box of a popover, a menu, a select… */
    public const string POPUP = 'border border-neutral-950 dark:border-white bg-white dark:bg-neutral-950 text-neutral-950 dark:text-white shadow-[0.25rem_0.25rem_0] shadow-black/12 dark:shadow-none';

    /**
     * The box of a dialog, centered in the viewport. The `<dialog>` resets come first. `z-50` puts a
     * dialog that is not modal (not in the top layer) above the page.
     */
    public const string DIALOG = 'm-0 inset-auto max-h-none fixed z-50 top-1/2 left-1/2 -mt-8 open:flex w-96 max-w-[calc(100vw-3rem)] -translate-x-1/2 -translate-y-1/2 flex-col gap-4 bg-white dark:bg-neutral-950 p-4 text-neutral-950 dark:text-white border border-neutral-950 dark:border-white shadow-[0.25rem_0.25rem_0] shadow-black/12 dark:shadow-none transition-[scale,opacity] duration-100 ease-out data-ending-style:scale-[0.98] data-ending-style:opacity-0 data-starting-style:scale-[0.98] data-starting-style:opacity-0 backdrop:bg-black/20 dark:backdrop:bg-black/50 backdrop:transition-opacity backdrop:duration-150 data-starting-style:backdrop:opacity-0 data-ending-style:backdrop:opacity-0';

    /** The arrow of a popover or a tooltip: a square turned 45 degrees, on the side of the anchor. */
    public const string ARROW = "absolute block w-3 h-1.5 overflow-clip data-[side=bottom]:top-[-6px] data-[side=left]:right-[-9px] data-[side=left]:rotate-90 data-[side=right]:left-[-9px] data-[side=right]:-rotate-90 data-[side=top]:bottom-[-6px] data-[side=top]:rotate-180 before:content-[''] before:absolute before:bottom-0 before:left-1/2 before:w-[calc(6px*sqrt(2))] before:h-[calc(6px*sqrt(2))] before:bg-white dark:before:bg-neutral-950 before:border before:border-neutral-950 dark:before:border-white before:[transform:translate(-50%,50%)_rotate(45deg)]";

    /** The popup of a menu or a context menu. */
    public const string MENU_POPUP = 'm-0 inset-auto overflow-visible relative origin-[var(--transform-origin)] border border-neutral-950 bg-white py-1 text-neutral-950 shadow-[0.25rem_0.25rem_0] shadow-black/12 outline-hidden transition-[scale,opacity] duration-100 ease-out data-ending-style:scale-[0.98] data-ending-style:opacity-0 data-starting-style:scale-[0.98] data-starting-style:opacity-0 dark:border-white dark:bg-neutral-950 dark:text-white dark:shadow-none [&[hidden]]:hidden';

    /** The popup of a menu in a Menubar: only the Menubar demo of Base UI stops the animation of an instant open or close. */
    public const string MENUBAR_POPUP = self::MENU_POPUP.' data-instant:transition-none';

    /** An item of a menu: the highlight is a bar inside the popup. */
    public const string MENU_ITEM = "flex w-full cursor-default py-2 pr-8 pl-4 text-left text-sm leading-4 outline-hidden select-none data-highlighted:relative data-highlighted:z-0 data-highlighted:text-white data-highlighted:before:absolute data-highlighted:before:inset-x-1 data-highlighted:before:inset-y-0 data-highlighted:before:z-[-1] data-highlighted:before:bg-neutral-950 data-highlighted:before:content-[''] data-disabled:text-neutral-500 dark:data-highlighted:text-neutral-950 dark:data-highlighted:before:bg-white dark:data-disabled:text-neutral-400";

    /** An item of a menu in a Menubar: Base UI gives it less space on the right. */
    public const string MENUBAR_ITEM = "flex w-full cursor-default items-center justify-between gap-4 py-2 pr-4 pl-4 text-left text-sm leading-4 font-normal outline-hidden select-none data-highlighted:relative data-highlighted:z-0 data-highlighted:text-white data-highlighted:before:absolute data-highlighted:before:inset-x-1 data-highlighted:before:inset-y-0 data-highlighted:before:z-[-1] data-highlighted:before:bg-neutral-950 data-highlighted:before:content-[''] data-disabled:text-neutral-500 dark:data-highlighted:text-neutral-950 dark:data-highlighted:before:bg-white dark:data-disabled:text-neutral-400";

    /** A checkbox item or a radio item of a menu: a column for the indicator, and a column for the label. */
    public const string MENU_CHECK_ITEM = "grid w-full cursor-default grid-cols-[1rem_1fr] items-center gap-2 py-2 pr-8 pl-2.5 text-left text-sm leading-4 outline-hidden select-none data-highlighted:relative data-highlighted:z-0 data-highlighted:text-white data-highlighted:before:absolute data-highlighted:before:inset-x-1 data-highlighted:before:inset-y-0 data-highlighted:before:z-[-1] data-highlighted:before:bg-neutral-950 data-highlighted:before:content-[''] data-disabled:text-neutral-500 dark:data-highlighted:text-neutral-950 dark:data-highlighted:before:bg-white dark:data-disabled:text-neutral-400";

    /** A checkbox item or a radio item of a menu in a Menubar. */
    public const string MENUBAR_CHECK_ITEM = "grid w-full cursor-default grid-cols-[1rem_1fr] items-center gap-2 py-2 pr-4 pl-2.5 text-left text-sm leading-4 outline-hidden select-none data-highlighted:relative data-highlighted:z-0 data-highlighted:text-white data-highlighted:before:absolute data-highlighted:before:inset-x-1 data-highlighted:before:inset-y-0 data-highlighted:before:z-[-1] data-highlighted:before:bg-neutral-950 data-highlighted:before:content-[''] data-disabled:text-neutral-500 dark:data-highlighted:text-neutral-950 dark:data-highlighted:before:bg-white dark:data-disabled:text-neutral-400";

    /** The item that opens a submenu. */
    public const string MENU_SUBMENU_TRIGGER = "flex w-full cursor-default items-center justify-between gap-4 py-2 pr-2 pl-4 text-left text-sm leading-4 font-normal outline-hidden select-none data-popup-open:relative data-popup-open:z-0 data-popup-open:before:absolute data-popup-open:before:inset-x-1 data-popup-open:before:inset-y-0 data-popup-open:before:z-[-1] data-popup-open:before:bg-neutral-100 data-popup-open:before:content-[''] data-highlighted:relative data-highlighted:z-0 data-highlighted:text-white data-highlighted:before:absolute data-highlighted:before:inset-x-1 data-highlighted:before:inset-y-0 data-highlighted:before:z-[-1] data-highlighted:before:bg-neutral-950 data-highlighted:before:content-[''] data-highlighted:data-popup-open:before:bg-neutral-950 data-disabled:text-neutral-500 dark:data-popup-open:before:bg-neutral-800 dark:data-highlighted:text-neutral-950 dark:data-highlighted:before:bg-white dark:data-highlighted:data-popup-open:before:bg-white dark:data-disabled:text-neutral-400";

    /** A checkbox box: a 16 px square, filled when it is checked. */
    public const string CHECKBOX = 'flex size-4 shrink-0 items-center justify-center border rounded-none p-0 border-neutral-950 bg-white text-white dark:border-white dark:bg-neutral-950 dark:text-neutral-950 data-checked:bg-neutral-950 data-checked:text-white dark:data-checked:bg-white dark:data-checked:text-neutral-950 data-indeterminate:bg-neutral-950 data-indeterminate:text-white dark:data-indeterminate:bg-white dark:data-indeterminate:text-neutral-950 data-disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-950 dark:focus-visible:outline-white';

    /** A toggle button in a toggle group or a toolbar: filled when it is pressed. */
    public const string GROUP_TOGGLE = 'flex size-8 items-center justify-center border-none rounded-none bg-transparent text-neutral-950 dark:text-white select-none hover:not-data-disabled:bg-neutral-100 dark:hover:not-data-disabled:bg-neutral-800 active:not-data-disabled:not-data-pressed:bg-neutral-200 dark:active:not-data-disabled:not-data-pressed:bg-neutral-700 data-pressed:bg-neutral-950 data-pressed:text-white dark:data-pressed:bg-white dark:data-pressed:text-neutral-950 data-pressed:hover:not-data-disabled:bg-neutral-950 data-pressed:hover:not-data-disabled:text-white dark:data-pressed:hover:not-data-disabled:bg-white dark:data-pressed:hover:not-data-disabled:text-neutral-950 focus-visible:relative focus-visible:z-1 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-neutral-950 dark:focus-visible:outline-white data-disabled:text-neutral-500';

    /** A button of a toolbar. */
    public const string TOOLBAR_BUTTON = 'flex h-8 min-w-8 items-center justify-center gap-2 border-0 bg-transparent px-3 font-[inherit] text-sm leading-none whitespace-nowrap font-normal text-neutral-950 select-none hover:not-data-disabled:bg-neutral-100 active:not-data-disabled:not-data-pressed:bg-neutral-200 data-pressed:bg-neutral-950 data-pressed:text-white data-pressed:hover:not-data-disabled:bg-neutral-950 data-pressed:hover:not-data-disabled:text-white dark:text-white dark:hover:not-data-disabled:bg-neutral-800 dark:active:not-data-disabled:not-data-pressed:bg-neutral-700 dark:data-pressed:bg-white dark:data-pressed:text-neutral-950 dark:data-pressed:hover:not-data-disabled:bg-white dark:data-pressed:hover:not-data-disabled:text-neutral-950 focus-visible:bg-transparent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-neutral-950 dark:focus-visible:outline-white data-disabled:text-neutral-500';

    /** The title and the description of a dialog or a popover. */
    public const string TITLE = 'text-base font-bold';
    public const string DESCRIPTION = 'text-sm text-neutral-600 dark:text-neutral-400';
}
