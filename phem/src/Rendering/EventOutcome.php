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

namespace Phem\Rendering;

use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The end of an event (see EventRenderer): the page must load again, the handler redirects,
 * or the tree rendered again.
 *
 * @internal
 */
final class EventOutcome
{
    /** @param list<string> $warnings the warnings of the dev-mode check (see SkippedRenderCheck) */
    private function __construct(
        public readonly bool $reload = false,
        public readonly ?RedirectResponse $redirect = null,
        public readonly array $warnings = [],
    ) {
    }

    /** The handler is not in the page any more (for example after a deploy). */
    public static function reload(): self
    {
        return new self(reload: true);
    }

    /** The handler returned a RedirectResponse: the page does not render again. */
    public static function redirect(RedirectResponse $response): self
    {
        return new self(redirect: $response);
    }

    /** @param list<string> $warnings */
    public static function rendered(array $warnings = []): self
    {
        return new self(warnings: $warnings);
    }
}
