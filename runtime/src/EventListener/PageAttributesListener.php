<?php

declare(strict_types=1);

namespace Rephp\EventListener;

use Symfony\Component\HttpKernel\Event\ControllerEvent;
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
        $page = $event->getRequest()->attributes->get('_rephp_page');
        if (!\is_string($page) || '' === $page) {
            return;
        }
        $attributes = $event->getAttributes();
        foreach ($this->attributesOf($page) as $class => $instances) {
            $attributes[$class] = [...$instances, ...($attributes[$class] ?? [])];
        }
        $event->setController($event->getController(), $attributes);
    }

    /** @return array<class-string, list<object>> */
    private function attributesOf(string $page): array
    {
        if (isset($this->attributes[$page])) {
            return $this->attributes[$page];
        }
        $reflection = class_exists($page) ? new \ReflectionClass($page) : new \ReflectionFunction($page);
        $out = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $class = $attribute->getName();
            if (Route::class === $class || !class_exists($class)) {
                continue;
            }
            // Not newInstance(): an attribute for classes and methods can be on a function page.
            $out[$class][] = new $class(...$attribute->getArguments());
        }

        return $this->attributes[$page] = $out;
    }
}
