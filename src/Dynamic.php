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
 * The `<Dynamic>` tag: an element whose tag name is a value. A component of a package can
 * render as another element, for example a button as a link:
 *
 *     use Phem\{Dynamic, Html, Node};
 *
 *     #[Component]
 *     function Button(string $tag = 'button', ?Html $children = null, mixed ...$rest): Node
 *     {
 *         return (
 *             <Dynamic tag={{ tag }} className={{ classes('px-4 py-2') }} {{ ...rest }}>
 *                 {{ children }}
 *             </Dynamic>
 *         );
 *     }
 *
 *     <Button tag="a" href="/docs">Docs</Button>   // <a class="px-4 py-2" href="/docs">Docs</a>
 *
 * `tag` is required. The other props are the attributes and the handlers of the element, as
 * with a spread, and the children are its content. A void element (`img`, `input`) has no end
 * tag and no children. The tag cannot be `script`, `style`, or an element whose attributes load
 * a document or a resource, such as `iframe` (see Phem\Dom\DynamicTag).
 *
 * It is an element, not a component: when it is the root of a component, the id of the
 * component goes on it, as on a static element.
 *
 * phemc compiles this tag in the template of its parent: the class has no code.
 */
final class Dynamic
{
    private function __construct()
    {
    }
}
