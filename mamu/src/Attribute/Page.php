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

namespace Mamu\Attribute;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Marks a class component as a page: a Symfony route to the component. The page renders the
 * document of the application, a component with a `<Root>` (see Mamu\Root).
 *
 *     #[Page('/scientists/{id}', name: 'scientist')]
 *     final class ScientistPage
 *     {
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
 * It has the options of `#[Route]`: path (or localized paths), name, requirements, defaults,
 * methods, host, schemes, condition, priority, env and aliases.
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS)]
final class Page extends Route
{
}
