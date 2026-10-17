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
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Adds the manifest signature to the cache key of each template.
 *
 * Twig resolves `{% component %}` from the manifest when it compiles a template. When a
 * component changes its kind (for example from a template component to a function component),
 * the caller templates must compile again, also when their source did not change.
 */
final class CacheKeyLoader implements LoaderInterface
{
    public function __construct(
        private readonly LoaderInterface $loader,
        private readonly Manifest $manifest,
    ) {
    }

    public function getSourceContext(string $name): Source
    {
        return $this->loader->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        return $this->loader->getCacheKey($name).':'.$this->manifest->twigSignature();
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->loader->isFresh($name, $time);
    }

    public function exists(string $name): bool
    {
        return $this->loader->exists($name);
    }
}
