<?php

declare(strict_types=1);

namespace Rephp;

/**
 * A context, like `createContext()` in React:
 *
 *     const ThemeContext = new Context('light');
 *     $theme = useContext(ThemeContext);
 *     <Provider context="App\ThemeContext" value="dark">...</Provider>
 */
final class Context
{
    public function __construct(public readonly mixed $default = null)
    {
    }
}
