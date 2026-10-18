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
     * Runs an event handler, as the `phem` Stimulus controller sends it.
     *
     * @param array<string, mixed> $event the DOM event (type, value, checked, key, form)
     */
    public function dispatch(string $handler, array $event): EventResult;

    /**
     * The #[State] properties of a component, as the browser keeps them: the root component
     * (the page, or the mounted component) by default.
     *
     * @param string|null $fiber the id of a fiber (the id of its `<phem-c>`), or null for the root component
     *
     * @return array<string, mixed>
     */
    public function state(?string $fiber = null): array;

    /** The HTTP status of the last response: 200 for a component. */
    public function status(): int;

    /** The URL of the last redirect response, if any. */
    public function redirect(): ?string;
}
