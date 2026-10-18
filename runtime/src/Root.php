<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu;

/**
 * The `<Root>` tag: the root element of a page, around the content that the events update. The
 * document of the application is a component that renders it once:
 *
 *     use Mamu\{Html, Root};
 *
 *     function Document(?Html $title = null, ?Html $children = null)
 *     {
 *         return (
 *             <html lang="en">
 *                 <head><title>{{ title ?? 'My app' }}</title>{{ importmap('app') }}</head>
 *                 <body><Root>{{ children }}</Root></body>
 *             </html>
 *         );
 *     }
 *
 * A page composes the document in its markup, and gives the title with a slot:
 *
 *     <Document>
 *         <Document:title>Home</Document:title>
 *         <h1>Home</h1>
 *     </Document>
 *
 * The root element has the attributes of the `mamu` Stimulus controller (the state, its
 * signature, and the DOM events). `tag` is its tag name (`div` by default). The other props are
 * its attributes: the `id` is `mamu-root` (null removes it), and the `data-controller` and
 * `data-action` values come after the values of Mamu.
 *
 *     <Root tag="main" className="app" data-controller="menu">{{ children }}</Root>
 *
 * The events render the page again, but they update only the content of the root: the
 * document around it (for example the title) does not change in the browser.
 *
 * mamuc and the Renderer know this component: the class has no code.
 */
final class Root
{
    private function __construct()
    {
    }
}
