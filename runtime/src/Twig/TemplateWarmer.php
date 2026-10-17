<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Twig;

use Mamu\Manifest\Manifest;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Twig\Environment;

/**
 * Compiles all mamuc templates into the Twig cache, so no template is parsed at runtime.
 *
 * `cache:warmup` runs it, and `mamu:warmup` (which `mamuc build` calls) runs it alone.
 */
final class TemplateWarmer implements CacheWarmerInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Manifest $manifest,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->manifest->exists()) {
            $this->compile();
        }

        return [];
    }

    /** @return int the number of templates */
    public function compile(): int
    {
        $n = 0;
        foreach ($this->manifest->templates() as $template) {
            $this->twig->load(Manifest::templateName($template));
            ++$n;
        }

        return $n;
    }
}
