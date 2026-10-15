<?php

declare(strict_types=1);

namespace Rephp\Twig;

use Rephp\Manifest;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Twig\Environment;

/**
 * Compiles all rhpc templates into the Twig cache, so no template is parsed at runtime.
 *
 * `cache:warmup` runs it, and `rephp:warmup` (which `rhpc build` calls) runs it alone.
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
        $this->twig->load(ComponentTokenParser::SLOT_HOST);

        return $n;
    }
}
