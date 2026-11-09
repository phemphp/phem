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
use Phem\ClassList;
use Phem\Dom\Attributes;
use Phem\Dom\ClassAttribute;
use Phem\Dom\DomEvents;
use Phem\Feed;
use Phem\Rendering\FeedContent;
use Phem\Rendering\FeedIdentity;
use Phem\Rendering\FeedItemToken;
use Phem\Rendering\FieldError;
use Phem\Rendering\Renderer;
use Phem\Rendering\RootTag;
use Phem\Rendering\TemplateComponentRenderer;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;
use Twig\Template;

/**
 * The Twig runtime of the compiled templates: `{% component %}`, `phem_on()`, `phem_loading()`,
 * `phem_progress()`, `phem_bind()`, `phem_error()`, `phem_class()` and `phem_attrs()`.
 * The compiled code gets it with `$this->env->getRuntime(PhemRuntime::class)`.
 */
final class PhemRuntime implements RuntimeExtensionInterface, ResetInterface
{
    /** @var array<int, FeedContent> the feed elements that render now, by object id of the feed */
    private array $feeds = [];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly TemplateComponentRenderer $templates = new TemplateComponentRenderer(),
        private readonly ClassAttribute $classes = new ClassAttribute(),
        private readonly FeedIdentity $identity = new FeedIdentity(),
        private readonly ?FeedItemToken $tokens = null,
    ) {
    }

    /** A render that threw before the end of a feed element leaves it here: the next request starts with none. */
    public function reset(): void
    {
        $this->feeds = [];
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

    /**
     * `phem:feed={{ messages }}` → `id="<fiber>-messages" data-phem-feed` (see Phem\Feed). phemc
     * compiles the loop of the element to feedItems() and feedItem(), and its end to feedEnd().
     *
     * @param array<string, mixed> $context the Twig context: `this` is the component of the markup
     */
    public function feed(array $context, mixed $feed): Markup
    {
        if (!$feed instanceof Feed) {
            throw new \InvalidArgumentException(\sprintf('"phem:feed" needs a Feed property of the class component, "%s" given.', get_debug_type($feed)));
        }

        $owner = $context['this'] ?? null;
        if (!\is_object($owner)) {
            throw new \LogicException(\sprintf('"phem:feed" shows the feed "%s" of a class component: the markup has no component. Run `phemc build` again.', $feed->name));
        }

        $content = $this->feeds[spl_object_id($feed)] = $this->renderer->openFeed($feed, $owner);

        return new Markup(' id="'.$content->id.'" data-phem-feed', 'UTF-8');
    }

    /**
     * The items of a feed element that render now: each item of the request one time (see
     * Phem\Feed::items()). A component in an item throws (see Renderer::enterFeedItem()).
     *
     * @return \Generator<int, object>
     */
    public function feedItems(Feed $feed): \Generator
    {
        foreach ($feed->items() as $item) {
            $this->renderer->enterFeedItem();

            try {
                yield $item;
            } finally {
                $this->renderer->leaveFeedItem();
            }
        }
    }

    /**
     * The HTML of an item (the `{% apply %}` around the body of the loop): its element gets the id of
     * the item and the signed item (see FeedItemToken). The feed element keeps it, and the loop
     * gives no HTML: the items go in place of the marker of feedEnd(), or in their own streams.
     */
    public function feedItem(string $html, Feed $feed, object $item): string
    {
        $content = $this->feeds[spl_object_id($feed)] ?? throw new \LogicException(\sprintf('An item of the feed "%s" renders outside its "phem:feed" element. Run `phemc build` again.', $feed->name));
        $tokens = $this->tokens ?? throw new \LogicException('A feed needs the phem.feed_item_token service.');
        $root = RootTag::find($html);
        if (null === $root) {
            throw new \LogicException(\sprintf('An item of the feed "%s" renders "%s": an item is one element.', $feed->name, mb_strimwidth(trim($html), 0, 80, '…')));
        }

        [$element, $end] = $root;
        if (1 === preg_match('/\sid\s*=/i', substr($element, 0, $end))) {
            throw new \LogicException(\sprintf('An item of the feed "%s" has its own `id`: Phem gives the element of an item the id of the item. Remove the `id`.', $feed->name));
        }
        [$class, $identifier] = $this->identity->of($feed->name, $item);
        $attributes = ' id="'.$this->identity->elementId($content->id, $feed->name, $item).'" data-phem-item="'.$tokens->sign($content->component, $feed->name, $class, $identifier).'"';
        $content->add($item, substr_replace($element, $attributes, $end, 0));

        return '';
    }

    /** The end of a feed element: the marker of its items (see FeedContent). */
    public function feedEnd(Feed $feed): Markup
    {
        $content = $this->feeds[spl_object_id($feed)] ?? null;
        unset($this->feeds[spl_object_id($feed)]);

        return new Markup($content?->marker() ?? '', 'UTF-8');
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
     * When the field has a validation error (see FieldErrors), it also gets `aria-invalid="true"`
     * and the id of the error in `aria-describedby`, after the ids of the markup
     * (`$describedBy`: phemc gives the `aria-describedby` of the field).
     *
     * @param string|list<mixed>|Bound $property
     */
    public function bind(string|array|Bound $property, mixed $value, string $tag, mixed $type = null, mixed $option = null, ?object $owner = null, mixed $describedBy = null): Markup
    {
        if ($property instanceof Bound) {
            [$property, $value, $owner] = [$property->target, $property->value, $property->owner];
        }

        $key = $this->renderer->addBind($property, $owner);
        $error = $this->renderer->fieldError($property, $owner);
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
                $out[] = \sprintf('value="%s"', Attributes::e(Attributes::bindValue($value, $type)));
            }
        }

        $describedBy = \is_scalar($describedBy) && '' !== trim((string) $describedBy) ? trim((string) $describedBy) : null;
        if (null !== $error) {
            $out[] = 'aria-invalid="true"';
            $describedBy = null !== $describedBy ? $describedBy.' '.$error->id : $error->id;
        }

        if (null !== $describedBy) {
            $out[] = \sprintf('aria-describedby="%s"', Attributes::e($describedBy));
        }

        return new Markup(implode(' ', $out), 'UTF-8');
    }

    /**
     * `phem:error={{ email }}`: the validation error of a bound #[State] property of the
     * component of the markup (`this`), or of a Bound prop. phemc renders the element only when
     * it is not null, with its id and its message. Null when the field has no error.
     *
     * @param string|list<mixed>|Bound $property
     */
    public function error(string|array|Bound $property, ?object $owner = null): ?FieldError
    {
        if ($property instanceof Bound) {
            [$property, $owner] = [$property->target, $property->owner];
        }

        return $this->renderer->fieldError($property, $owner);
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
     * `className={{ x }}` → `class="…"`: a string, a `class` array or a ClassList (see
     * ClassAttribute). phemc gives a quoted value with an expression as a list:
     * `className="btn {{ extra }}"` → `phem_class(['btn', extra])`.
     */
    public function class(mixed $value): Markup
    {
        return $this->classes->render($value);
    }

    /**
     * The attributes of an HTML element with a spread: `<button type="button" {{ ...rest }}>`
     * → `phem_attrs({'type': 'button'}, rest)`. As in React, a later value replaces an earlier
     * value of the same attribute. `onClick` (and the other `on*` names) binds a handler.
     * Two values of `class` merge when one of them is a ClassList (see ClassList::merge()).
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
                $name = Attributes::name((string) $name);
                $merged[$name] = 'class' === $name && \array_key_exists($name, $merged) ? ClassList::merge($merged[$name], $value) : $value;
            }
        }

        $out = [];
        foreach ($merged as $name => $value) {
            $out[] = (string) match (true) {
                'key' === $name => Attributes::key($value),
                'class' === $name => $this->classes->render($value),
                'style' === $name => Attributes::style($value),
                'phem:loading' === $name => $this->loading($value),
                'phem:progress' === $name => $this->progress($value),
                'phem:bind' === $name => throw new \InvalidArgumentException('"phem:bind" cannot come from a spread: write it on the element, with the name of a #[State] property.'),
                'phem:error' === $name => throw new \InvalidArgumentException('"phem:error" cannot come from a spread: write it on the element, with the name of a #[State] property.'),
                'phem:feed' === $name => throw new \InvalidArgumentException('"phem:feed" cannot come from a spread: write it on the element, with the name of a Feed property.'),
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
