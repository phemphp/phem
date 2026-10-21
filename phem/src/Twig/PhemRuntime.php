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

use Phem\Bound;
use Phem\Dom\Attributes;
use Phem\Dom\DomEvents;
use Phem\Rendering\Renderer;
use Phem\Rendering\TemplateComponentRenderer;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;
use Twig\Template;

/**
 * The Twig runtime of the compiled templates: `{% component %}`, `phem_on()`, `phem_loading()`,
 * `phem_progress()` and `phem_attrs()`.
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
        return $this->templates->render($template, $fqcn, $props, $key, $caller, $slots, $this->renderer->pass(...));
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
     * `phem:loading={{ save }}` → `data-phem-loading="<scope>" hidden`: the browser shows the
     * element only while a `save` event of this component (or of a component in it) runs.
     */
    public function loading(mixed $handler): Markup
    {
        return $this->handlerScope('loading', $handler);
    }

    /**
     * `phem:progress={{ save }}` → `data-phem-progress="<scope>" hidden`: while the files of a
     * `save` event upload, the browser shows the element with the progress (the `value` of a
     * `<progress>`, and the `--phem-progress` CSS property).
     */
    public function progress(mixed $handler): Markup
    {
        return $this->handlerScope('progress', $handler);
    }

    private function handlerScope(string $name, mixed $handler): Markup
    {
        if (null === $handler || false === $handler) {
            return new Markup('', 'UTF-8');
        }
        if (!$handler instanceof \Closure) {
            throw new \InvalidArgumentException(\sprintf('The "phem:%s" handler must be a function, "%s" given. Pass the function (`$this->name(...)`), do not call it.', $name, get_debug_type($handler)));
        }
        $scope = $this->renderer->handlerScope($handler) ?? 'static';

        return new Markup(\sprintf('data-phem-%s="%s" hidden', $name, Attributes::e($scope)), 'UTF-8');
    }

    /**
     * `phem:bind={{ query }}` on a form field: the current value of the #[State] property, and
     * `data-phem-bind="<fiber>.<property>"`. The browser sends the new value with the next event
     * (see BoundValues). phemc gives the tag, the `type` and the `value` attributes of the field,
     * and the component of the markup (`this`). For a path (`phem:bind={{ filters.status }}`),
     * phemc gives the property and the keys: `['filters', 'status']`.
     *
     *  - a text input: `value="…"`;
     *  - a checkbox: `checked` when the value is true, or when a list has the `value` of the
     *    checkbox (a group of checkboxes, with `data-phem-bind-list`);
     *  - a radio button: `checked` when the value is its `value`;
     *  - a `<textarea>` and a `<select>`: only the attribute. phemc writes the text of the
     *    `<textarea>`, and the `selected` of each `<option>` (see selected()).
     *
     * In a child component, `phem:bind={{ value }}` binds a Bound prop: the property of the parent.
     *
     * @param string|list<mixed>|Bound $property
     */
    public function bind(string|array|Bound $property, mixed $value, string $tag, mixed $type = null, mixed $option = null, ?object $owner = null): Markup
    {
        if ($property instanceof Bound) {
            [$property, $value, $owner] = [$property->target, $property->value, $property->owner];
        }
        $key = $this->renderer->addBind($property, $owner);
        $out = null !== $key ? [\sprintf('data-phem-bind="%s"', Attributes::e($key))] : [];
        $type = \is_string($type) ? strtolower($type) : 'text';
        if ('input' === strtolower($tag)) {
            if ('checkbox' === $type && \is_array($value)) {
                $out[] = 'data-phem-bind-list';
                if (\in_array(Attributes::bindValue($option ?? 'on'), array_map(Attributes::bindValue(...), $value), true)) {
                    $out[] = 'checked';
                }
            } elseif ('checkbox' === $type) {
                if (true === $value) {
                    $out[] = 'checked';
                }
            } elseif ('radio' === $type) {
                if (null !== $value && Attributes::bindValue($value) === Attributes::bindValue($option ?? 'on')) {
                    $out[] = 'checked';
                }
            } else {
                $out[] = \sprintf('value="%s"', Attributes::e(Attributes::bindValue($value)));
            }
        }

        return new Markup(implode(' ', $out), 'UTF-8');
    }

    /**
     * `<TextField phem:bind={{ query }} />` → the Bound prop of the child: the #[State] property
     * of the component of the markup (`this`), or a path in it, with its current value.
     *
     * @param string|list<mixed> $property
     */
    public function bound(string|array $property, mixed $value, ?object $owner = null): Bound
    {
        return new Bound($property, $value, $owner);
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
                'phem:loading' === $name => $this->loading($value),
                'phem:progress' === $name => $this->progress($value),
                'phem:bind' === $name => throw new \InvalidArgumentException('"phem:bind" cannot come from a spread: write it on the element, with the name of a #[State] property.'),
                'phem:live' === $name => Attributes::attr('data-phem-live', true === $value ? true : null),
                \in_array($name, ['phem:debounce', 'phem:throttle'], true) => Attributes::timing(substr($name, 5), $value),
                1 === preg_match('/^on[A-Z]/', $name) => $this->on(strtolower(substr($name, 2)), $value),
                0 === stripos($name, 'on') => '',
                default => Attributes::attr($name, $value),
            };
        }

        return new Markup(implode(' ', array_filter($out)), 'UTF-8');
    }
}
