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

namespace Phem\Twig;

use Phem\Dom\Attributes;
use Phem\Dom\DomEvents;
use Phem\Rendering\Renderer;
use Phem\Rendering\TemplateComponentRenderer;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;
use Twig\Template;

/**
 * The Twig runtime of the compiled templates: `{% component %}`, `phem_on()` and `phem_attrs()`.
 * The compiled code gets it with `$this->env->getRuntime(PhemRuntime::class)`.
 */
final class PhemRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly TemplateComponentRenderer $templates = new TemplateComponentRenderer(),
    ) {
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     */
    public function component(string $fqcn, array $props, mixed $key, ?Template $slots, array $caller): string
    {
        return $this->renderer->component($fqcn, $props, $key, $slots, $caller);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     * @param list<string>         $slots
     */
    public function template(Template $template, string $fqcn, array $props, mixed $key, array $caller, array $slots = []): string
    {
        return $this->templates->render($template, $fqcn, $props, $key, $caller, $slots);
    }

    /** `onClick={{ f }}` → `data-phem-on-click="<handler id>"`. */
    public function on(string $event, mixed $handler): Markup
    {
        if (null === $handler || false === $handler) {
            return new Markup('', 'UTF-8');
        }
        // Only a Closure: a string or an array can also be callable (`'system'`), and it can come
        // from data in a spread.
        if (!$handler instanceof \Closure) {
            throw new \InvalidArgumentException(\sprintf('The "%s" handler must be a function, "%s" given. Pass the function (`$this->name(...)`, `fn () => ...`), do not call it.', $event, get_debug_type($handler)));
        }
        $id = $this->renderer->addHandler($handler) ?? 'static';

        return new Markup(\sprintf('data-phem-on-%s="%s"', DomEvents::name($event), Attributes::e($id)), 'UTF-8');
    }

    /**
     * The attributes of an HTML element with a spread: `<button type="button" {{ ...rest }}>`
     * → `phem_attrs({'type': 'button'}, rest)`. As in React, a later value replaces an earlier
     * value of the same attribute. `onClick` (and the other `on*` names) binds a handler.
     *
     * A spread can contain data, so its names are not trusted: an invalid name is not rendered,
     * and an `on*` name is never an inline script (`onclick="..."`), as in React. phemc writes
     * the static `onclick="..."` of the markup outside of this call.
     */
    public function attrs(mixed ...$groups): Markup
    {
        $merged = [];
        foreach ($groups as $attrs) {
            if (null !== $attrs && !is_iterable($attrs)) {
                // Not (array) $object: that gives all of its properties, also the private ones.
                throw new \InvalidArgumentException(\sprintf('A spread (`{{ ...attrs }}`) needs a hash of attributes, "%s" given.', get_debug_type($attrs)));
            }
            foreach ($attrs ?? [] as $name => $value) {
                $merged[Attributes::name((string) $name)] = $value;
            }
        }
        $out = [];
        foreach ($merged as $name => $value) {
            $out[] = (string) match (true) {
                'key' === $name => Attributes::key($value),
                'style' === $name => Attributes::style($value),
                1 === preg_match('/^on[A-Z]/', $name) => $this->on(strtolower(substr($name, 2)), $value),
                0 === stripos($name, 'on') => '',
                default => Attributes::attr($name, $value),
            };
        }

        return new Markup(implode(' ', array_filter($out)), 'UTF-8');
    }
}
