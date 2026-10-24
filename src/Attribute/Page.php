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

use Symfony\Component\Routing\Attribute\Route;

/**
 * Marks a component as a page: a Symfony route to the component. The page renders the document
 * of the application, a component with a `<Root>` (see Phem\Root).
 *
 *     #[Page('/scientists/{id}', name: 'scientist')]
 *     final class ScientistPage
 *     {
 *         #[Path] public int $id;
 *
 *         public function render()
 *         {
 *             return (
 *                 <Document>
 *                     <Document:title>{{ scientist.name }}</Document:title>
 *                     ...
 *                 </Document>
 *             );
 *         }
 *     }
 *
 * A page with no state and no route values can be a function:
 *
 *     #[Page('/about')]
 *     function About()
 *     {
 *         return <Document>...</Document>;
 *     }
 *
 * The class or the function needs no `#[Component]`. A function page has no #[Path] properties,
 * so its path has no route values (`{id}`) and its defaults have no route values either. Its
 * components can have state and handlers.
 *
 * It has the options of `#[Route]`: path (or localized paths), name, requirements, defaults,
 * methods, host, schemes, condition, priority, env and aliases.
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION)]
final class Page extends Route
{
}
