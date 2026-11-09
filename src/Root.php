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

namespace Phem;

/**
 * The `<Root>` tag: the root element of a page, around the content that the events update. The
 * document of the application is a component that renders it once:
 *
 *     use Phem\{Html, Root};
 *
 *     function Document(?string $title = null, ?Html $children = null)
 *     {
 *         return (
 *             <html lang="en">
 *                 <head><title>{{ title ?? 'My app' }}</title>{{ importmap('app') }}</head>
 *                 <body><Root>{{ children }}</Root></body>
 *             </html>
 *         );
 *     }
 *
 * A page composes the document in its markup, and gives the title with the `title` prop:
 *
 *     <Document title="Home">
 *         <h1>Home</h1>
 *     </Document>
 *
 * The root element has the attributes of the `phem` Stimulus controller (the state, its
 * signature, and the DOM events). `tag` is its tag name (`div` by default). The other props are
 * its attributes: the `id` is `phem-root` (null removes it), and the `data-controller` and
 * `data-action` values come after the values of Phem.
 *
 *     <Root tag="main" className="app" data-controller="menu">{{ children }}</Root>
 *
 * The events render the page again, but they update only the content of the root: the
 * document around it (for example the title) does not change in the browser.
 *
 * phemc and the Renderer know this component: the class has no code.
 */
final class Root
{
    private function __construct()
    {
    }
}
