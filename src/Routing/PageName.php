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

namespace Phem\Routing;

/**
 * The name of a page from its component: a class name (`ScientistPage::class`), or the
 * first-class callable of a function page (`ScientistPage(...)`, with `use function`). A function
 * has no `::class`, so its callable gives its name.
 */
final class PageName
{
    /**
     * @throws \InvalidArgumentException when the closure is not a function
     */
    public static function of(string|\Closure $page): string
    {
        if (\is_string($page)) {
            return ltrim($page, '\\');
        }

        // A method (`$this->show(...)`) or an anonymous function has no function name.
        $name = (new \ReflectionFunction($page))->getName();
        if (!\function_exists($name)) {
            throw new \InvalidArgumentException(\sprintf('A page is a class name, or the first-class callable of a function page (`ScientistPage(...)`), "%s" given.', $name));
        }

        return $name;
    }
}
