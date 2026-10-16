<?php

declare(strict_types=1);

namespace Rephp\Manifest;

/**
 * How a component renders. rhpc writes the kind of each component in the manifest.
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
