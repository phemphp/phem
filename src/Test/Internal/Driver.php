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

namespace Phem\Test\Internal;

/**
 * Renders a component or a page for a ComponentTester, and runs its events.
 *
 * @internal
 */
interface Driver
{
    /** The first render: a full HTML document, or the HTML of a component. */
    public function html(): string;

    /** Whether html() is a full document (a page), not the HTML of a component. */
    public function isDocument(): bool;

    /**
     * Runs the event handlers of one event, as the `phem` Stimulus controller sends it.
     *
     * @param list<string>         $handlers the handler ids: none for a `phem:live` event or a refresh, more than one for a load of many boundaries
     * @param array<string, mixed> $event    the DOM event (type, value, checked, key, form), and the
     *                                       files of the form (`files`: [field name, path] pairs)
     * @param array<string, mixed> $bound    the bound fields that changed: "<fiber>.<property>" => value
     * @param list<string>         $stale    the components whose element a push changed (see Phem\Rendering\Tree::markStale())
     */
    public function dispatch(array $handlers, array $event, array $bound = [], array $stale = []): EventResult;

    /**
     * The #[State] properties of a component, as the browser keeps them: the root component
     * (the page, or the mounted component) by default.
     *
     * @param string|null $fiber the id of a fiber (its `data-phem-c`), or null for the root component
     *
     * @return array<string, mixed>
     */
    public function state(?string $fiber = null): array;

    /**
     * The URL that the browser shows, with the query of the #[Url] properties (see
     * Phem\State\UrlQuery): "/" and the query for a component.
     */
    public function url(): string;

    /** The HTTP status of the last response: 200 for a component. */
    public function status(): int;

    /** The URL of the last redirect response, if any. */
    public function redirect(): ?string;
}
