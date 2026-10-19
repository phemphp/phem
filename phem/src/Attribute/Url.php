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

namespace Phem\Attribute;

/**
 * Keeps a #[State] property in the query string of the page URL, so the value survives a
 * reload, a shared link, and the back button:
 *
 *     #[State, Url(as: 'q')]
 *     public string $query = '';
 *
 * The first render of the page reads the value from the query string, after mount(). A value
 * that does not fit the type of the property is ignored. After each event, the browser updates
 * the URL. A value that is equal to the default value of the property removes its key. With
 * `except`, that other value removes the key:
 *
 *     #[State, Url(except: 'name')]
 *     public string $sort;  // set in mount()
 *
 * The type is a string, an int, a float, a bool, a backed enum, or a list of them (nullable or
 * not). The query string comes from the user: do not trust its values.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Url
{
    /** The value of `except` for the default value of the property. */
    public const string DEFAULT = "\0default";

    /**
     * @param string|null $as      the key in the query string, the name of the property by default
     * @param bool        $history true to add an entry to the browser history when the value changes, false to replace the current entry
     * @param mixed       $except  the value with no key in the query string, the default value of the property by default. Give the property this value when the URL has no key (in mount()).
     */
    public function __construct(
        public readonly ?string $as = null,
        public readonly bool $history = false,
        public readonly mixed $except = self::DEFAULT,
    ) {
    }
}
