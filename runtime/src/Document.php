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

use Mamu\Http\PageView;

/**
 * The HTML document of a page: `<html>`, `<head>` and `<body>` around the root element of the
 * page. The application writes it as a class in its .phpm sources:
 *
 *     class Document implements \Mamu\Document
 *     {
 *         protected string $title = 'My app';
 *
 *         public function title(): string
 *         {
 *             return $this->title;
 *         }
 *
 *         public function document(PageView $page)
 *         {
 *             $title = $this->title();
 *
 *             return (
 *                 <html lang="en">
 *                     <head><title>{{ title }}</title>{{ importmap('app') }}</head>
 *                     <body>{{ mamu_root(page) }}</body>
 *                 </html>
 *             );
 *         }
 *     }
 *
 * A page is a class that extends it (directly or through a subclass, for another layout). The
 * page changes the title (`protected string $title = 'Home';`, or a `title()` method for a
 * dynamic title), or the whole document.
 *
 * The document renders once, after the page, with the state of the page. The events do not
 * render it again.
 */
interface Document
{
    /**
     * @param PageView $page the rendered page: print it with `{{ mamu_root(page) }}`
     *
     * @return mixed the markup of the document, without the `<!DOCTYPE html>`
     */
    public function document(PageView $page);
}
