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

namespace Phem\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The route values of a page request, by prop name.
 *
 * As for the Doctrine controller arguments, `{slug:post}` gives the `post` entity prop the
 * criteria `['slug' => value]`, and `{name:post.title}` gives it `['title' => value]`. A null
 * value (the default of an optional route value) gives the prop null.
 */
final class RouteValues
{
    /** @return array<string, mixed> */
    public static function fromRequest(Request $request): array
    {
        $values = (array) $request->attributes->get('_route_params', []);
        $criteria = [];
        foreach ((array) $request->attributes->get('_route_mapping', []) as $parameter => $target) {
            if (!\array_key_exists($parameter, $values)) {
                continue;
            }

            [$prop, $field] = \is_array($target) ? $target : [$target, $parameter];
            $criteria[$prop][$field] = $values[$parameter];
            unset($values[$parameter]);
        }

        // An optional value without a value (its default is null) finds no entity: no query.
        foreach ($criteria as $prop => $fields) {
            if (\in_array(null, $fields, true)) {
                $criteria[$prop] = null;
            }
        }

        return $criteria + $values;
    }
}
