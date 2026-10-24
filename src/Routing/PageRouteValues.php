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

namespace Phem\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * The props of a page that get a route value, from the routes of the page (see PageLoader). A
 * function page gets the services and the Request in its parameters too, and only its routes
 * tell the route values apart.
 *
 * It reads the route collection once, when it needs it first.
 */
final class PageRouteValues
{
    /** @var array<string, list<string>>|null page => prop names */
    private ?array $pages = null;

    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * The props of the page with a route value in one of its routes.
     *
     * @return list<string>
     */
    public function of(string $page): array
    {
        if (null === $this->pages) {
            $this->pages = [];
            foreach ($this->router->getRouteCollection() as $route) {
                $of = $route->getDefault('_phem_page');
                if (\is_string($of)) {
                    $this->pages[$of] = array_values(array_unique([...$this->pages[$of] ?? [], ...array_values(self::props($route))]));
                }
            }
        }

        return $this->pages[$page] ?? [];
    }

    /**
     * The route values of a route, with the prop that gets each one: a mapped value
     * (`{slug:post}`) goes to the prop of its entity.
     *
     * @return array<string, string> route value => prop
     */
    public static function props(Route $route): array
    {
        $mapping = (array) ($route->getDefault('_route_mapping') ?? []);

        $props = [];
        foreach (array_unique([...$route->compile()->getVariables(), ...array_keys($route->getDefaults())]) as $value) {
            if (str_starts_with($value, '_')) {
                continue;
            }

            $props[$value] = match (true) {
                !isset($mapping[$value]) => $value,
                \is_array($mapping[$value]) => $mapping[$value][0],
                default => $mapping[$value],
            };
        }

        return $props;
    }
}
