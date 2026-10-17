<?php

declare(strict_types=1);

namespace Mamu\Twig;

use Mamu\Manifest\Manifest;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Loads the templates that mamuc compiled, as `@phpm/<file>.<n>.twig`.
 *
 * The directory exists only after the first `mamuc build`, so the loader opens it on first use.
 */
final class TemplateLoader implements LoaderInterface
{
    private ?FilesystemLoader $loader = null;

    public function __construct(private readonly string $templateDir)
    {
    }

    public function getSourceContext(string $name): Source
    {
        return $this->loader($name)->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        return $this->loader($name)->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->loader($name)->isFresh($name, $time);
    }

    public function exists(string $name): bool
    {
        return str_starts_with($name, '@'.Manifest::TEMPLATE_NAMESPACE.'/') && null !== $this->open() && $this->open()->exists($name);
    }

    private function loader(string $name): FilesystemLoader
    {
        return $this->open() ?? throw new LoaderError(\sprintf('Template "%s" is not defined: no mamuc build in "%s". Run `mamuc build src` first.', $name, $this->templateDir));
    }

    private function open(): ?FilesystemLoader
    {
        if (null === $this->loader && is_dir($this->templateDir)) {
            $this->loader = new FilesystemLoader();
            $this->loader->addPath($this->templateDir, Manifest::TEMPLATE_NAMESPACE);
        }

        return $this->loader;
    }
}
