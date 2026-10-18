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

namespace Mamu\Manifest;

/**
 * How a component renders. mamuc writes the kind of each component in the manifest.
 */
enum ComponentKind: string
{
    /** A `#[Component]` class: a service with props, state and a `render()` method. */
    case ClassComponent = 'class';
    /** A PHP function: it gets its props as arguments. */
    case FunctionComponent = 'function';
    /** Markup only: it renders as plain Twig, with no PHP call. */
    case TemplateComponent = 'template';
}
