<?php

declare(strict_types=1);

namespace Rephp;

/**
 * The output of `rhpc build`: the components, the pages, and the template line map.
 *
 * It loads the manifest and the compiled PHP files on first use, so an application without a
 * build can still boot (for example to run `cache:clear`).
 */
final class Manifest
{
    /** The Twig namespace of the compiled templates. */
    public const TEMPLATE_NAMESPACE = 'rhp';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        private readonly string $buildDir,
        private readonly string $sourceDir,
    ) {
    }

    public function buildDir(): string
    {
        return $this->buildDir;
    }

    public function sourceDir(): string
    {
        return $this->sourceDir;
    }

    public function file(): string
    {
        return $this->buildDir.'/manifest.php';
    }

    public function exists(): bool
    {
        return is_file($this->file());
    }

    /** @return array<string, mixed>|null the definition of a component (kind, props, slots, template) */
    public function component(string $fqcn): ?array
    {
        return $this->data()['components'][ltrim($fqcn, '\\')] ?? null;
    }

    /** @return list<string> the page components: the components with a `#[Route]` attribute */
    public function pages(): array
    {
        return $this->data()['pages'] ?? [];
    }

    /** @return list<string> the template names, without the Twig namespace */
    public function templates(): array
    {
        return array_keys($this->data()['templates'] ?? []);
    }

    /** The Twig name of a compiled template: `@rhp/pages/index.0.twig`. */
    public static function templateName(string $template): string
    {
        return '@'.self::TEMPLATE_NAMESPACE.'/'.$template;
    }

    /**
     * The `.rhp` source and line of a line in a compiled template.
     *
     * @return array{file: string, path: string, line: int}|null
     */
    public function location(string $twigName, int $templateLine): ?array
    {
        $prefix = '@'.self::TEMPLATE_NAMESPACE.'/';
        if (!str_starts_with($twigName, $prefix)) {
            return null;
        }
        $tpl = $this->data()['templates'][substr($twigName, \strlen($prefix))] ?? null;
        if (null === $tpl) {
            return null;
        }

        return [
            'file' => 'src/'.$tpl['source'],
            'path' => $this->sourceDir.'/'.$tpl['source'],
            'line' => $tpl['line'] + max(0, $templateLine - 1),
        ];
    }

    /** @return list<string> the absolute paths of the compiled PHP files */
    public function phpFiles(): array
    {
        return array_map(fn (string $f): string => $this->buildDir.'/'.$f, $this->data()['files'] ?? []);
    }

    /** Loads the compiled components (functions and classes). */
    public function load(): void
    {
        $this->data();
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }
        if (!$this->exists()) {
            throw new \RuntimeException(\sprintf('No compiled project in "%s". Run `rhpc build src` first.', $this->buildDir));
        }
        $data = require $this->file();
        require_once $this->buildDir.'/autoload.php';

        return $this->data = $data;
    }
}
