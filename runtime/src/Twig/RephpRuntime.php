<?php

declare(strict_types=1);

namespace Rephp\Twig;

use Rephp\Rendering\Renderer;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;
use Twig\Template;

/**
 * The Twig runtime of the compiled templates: `{% component %}`, `rhp_on()` and `rhp_attrs()`.
 * The compiled code gets it with `$this->env->getRuntime(RephpRuntime::class)`.
 */
final class RephpRuntime implements RuntimeExtensionInterface
{
    /** React event names → DOM event names. */
    private const EVENTS = ['doubleclick' => 'dblclick', 'focus' => 'focusin', 'blur' => 'focusout'];

    public function __construct(private readonly Renderer $renderer)
    {
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
     */
    public function template(Template $template, string $fqcn, array $props, mixed $key, array $caller): string
    {
        return $this->renderer->template($template, $fqcn, $props, $key, $caller);
    }

    /** `onClick={{ f }}` → `data-rh-on-click="<handler id>"`. */
    public function on(string $event, mixed $handler): Markup
    {
        if (null === $handler || false === $handler) {
            return new Markup('', 'UTF-8');
        }
        if (!\is_callable($handler)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" handler must be a function, "%s" given. Pass the function, do not call it.', $event, get_debug_type($handler)));
        }
        $id = $this->renderer->addHandler(\Closure::fromCallable($handler)) ?? 'static';
        $event = self::EVENTS[$event] ?? $event;

        return new Markup(\sprintf('data-rh-on-%s="%s"', $event, RephpExtension::e($id)), 'UTF-8');
    }

    /**
     * The attributes of an HTML element with a spread: `<button type="button" {{ ...rest }}>`
     * → `rhp_attrs({'type': 'button'}, rest)`. As in React, a later value replaces an earlier
     * value of the same attribute. `onClick` (and the other `on*` names) binds a handler.
     */
    public function attrs(mixed ...$groups): Markup
    {
        $merged = [];
        foreach ($groups as $attrs) {
            foreach ((array) $attrs as $name => $value) {
                $name = (string) $name;
                $merged[['className' => 'class', 'htmlFor' => 'for'][$name] ?? $name] = $value;
            }
        }
        $out = [];
        foreach ($merged as $name => $value) {
            $out[] = (string) match (true) {
                'key' === $name => RephpExtension::key($value),
                'style' === $name => RephpExtension::style($value),
                1 === preg_match('/^on[A-Z]/', $name) => $this->on(strtolower(substr($name, 2)), $value),
                default => RephpExtension::attr($name, $value),
            };
        }

        return new Markup(implode(' ', array_filter($out)), 'UTF-8');
    }
}
