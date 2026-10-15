<?php

declare(strict_types=1);

namespace Rephp\Attribute;

/**
 * Marks the page component of a file in `src/pages/`. The file path is the route:
 * `src/pages/examples/Counter.rhp` → `/examples/Counter`. A file has at most one page.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_CLASS)]
final class Page
{
}
