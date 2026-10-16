<?php

declare(strict_types=1);

namespace Rephp\Manifest;

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
    private ?string $twigSignature = null;
    /** @var array<string, ComponentDefinition|null> */
    private array $definitions = [];

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

    public function component(string $fqcn): ?ComponentDefinition
    {
        $fqcn = ltrim($fqcn, '\\');
        if (\array_key_exists($fqcn, $this->definitions)) {
            return $this->definitions[$fqcn];
        }
        $data = $this->data()['components'][$fqcn] ?? null;

        return $this->definitions[$fqcn] = null !== $data ? ComponentDefinition::fromArray($fqcn, $data) : null;
    }

    /** @return iterable<ComponentDefinition> */
    public function components(): iterable
    {
        foreach (array_keys($this->data()['components'] ?? []) as $fqcn) {
            yield $this->component($fqcn);
        }
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

    /**
     * A hash of what Twig reads from the manifest when it compiles `{% component %}`: the kind
     * and the template of each component. It is empty without a build.
     */
    public function twigSignature(): string
    {
        if (null !== $this->twigSignature) {
            return $this->twigSignature;
        }
        if (!$this->exists()) {
            return '';
        }
        $parts = [];
        foreach ($this->data()['components'] ?? [] as $fqcn => $def) {
            $parts[$fqcn] = [$def['kind'] ?? null, $def['template'] ?? null];
        }
        ksort($parts);

        return $this->twigSignature = hash('xxh128', serialize($parts));
    }

    /** The Twig name of a compiled template: `@rhp/Pages/index.0.twig`. */
    public static function templateName(string $template): string
    {
        return '@'.self::TEMPLATE_NAMESPACE.'/'.$template;
    }

    /**
     * The `.rhp` source of a compiled template.
     *
     * @param string $template the template name, without the Twig namespace
     *
     * @return array{source: string, line: int}|null
     */
    public function template(string $template): ?array
    {
        return $this->data()['templates'][$template] ?? null;
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
