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

namespace Phem\EventListener;

use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gives the attributes of a page component to the controller of its requests, as for a
 * controller method: `#[IsGranted]`, `#[Cache]` and the other controller attributes of
 * Symfony apply to the page (PageController) and to its events (EventController).
 *
 * Without it, Symfony reads the attributes of PageController, and it ignores the attributes of
 * the page.
 */
final class PageAttributesListener
{
    /** @var array<string, array<class-string, list<object>>> */
    private array $attributes = [];

    public function onKernelController(ControllerEvent $event): void
    {
        $page = $event->getRequest()->attributes->get('_phem_page');
        if (!\is_string($page) || '' === $page) {
            return;
        }
        $own = $this->attributesOf($page);
        if (Kernel::VERSION_ID >= 80100) {
            // Symfony 8.1 takes a flat list of attributes.
            $event->setController($event->getController(), [...array_merge(...array_values($own)), ...$event->getAttributes('*')]);

            return;
        }
        $attributes = $event->getAttributes();
        foreach ($own as $class => $instances) {
            $attributes[$class] = [...$instances, ...($attributes[$class] ?? [])];
        }
        // @phpstan-ignore argument.type (Symfony 7.4 and 8.0 take the attributes grouped by class)
        $event->setController($event->getController(), $attributes);
    }

    /** @return array<class-string, list<object>> */
    private function attributesOf(string $page): array
    {
        if (isset($this->attributes[$page])) {
            return $this->attributes[$page];
        }
        if (!class_exists($page)) {
            return $this->attributes[$page] = [];
        }
        $out = [];
        foreach ((new \ReflectionClass($page))->getAttributes() as $attribute) {
            $class = $attribute->getName();
            if (!class_exists($class) || is_a($class, Route::class, true)) {
                continue;
            }
            $out[$class][] = $attribute->newInstance();
        }

        return $this->attributes[$page] = $out;
    }
}
