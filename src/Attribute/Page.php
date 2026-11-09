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
 *                 <Document title={{ scientist.name }}>
 *                     ...
 *                 </Document>
 *             );
 *         }
 *     }
 *
 * A page with no state can be a function. Its parameters get the arguments of a controller:
 * the route values by name, the Request, the services (see Phem\Argument\CallableArguments):
 *
 *     #[Page('/items/{id}')]
 *     function ItemPage(int $id, ItemRepository $items)
 *     {
 *         return <Document>...</Document>;
 *     }
 *
 * The class or the function needs no `#[Component]`. A page is not a tag, so a function page
 * has no props. Its components can have state and handlers.
 *
 * It has the options of `#[Route]`: path (or localized paths), name, requirements, defaults,
 * methods, host, schemes, condition, priority, env and aliases.
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION)]
final class Page extends Route
{
}
