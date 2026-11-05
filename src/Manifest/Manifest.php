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

namespace Phem\Manifest;

use Phem\ErrorBoundary;
use Phem\Root;
use Phem\Suspense;

/**
 * The output of `phemc build`: the components, the pages, and the template line map. It has the
 * `.phem` sources of the application and of its Composer packages. Each page has the name of its
 * package (`''` for a page of the application).
 *
 * It loads the manifest and the compiled PHP files on first use, so an application without a
 * build can still boot (for example to run `cache:clear`).
 *
 * @phpstan-type ManifestData array{
 *     version?: string,
 *     components?: array<string, array<string, mixed>>,
 *     pages?: array<string, string>,
 *     templates?: array<string, array{source: string, line: int}>,
 *     files?: list<string>,
 * }
 */
final class Manifest
{
    /** The Twig namespace of the compiled templates. */
    public const TEMPLATE_NAMESPACE = 'phemc';

    /** @var ManifestData|null */
    private ?array $data = null;
    private ?string $twigSignature = null;
    /** @var array<string, ComponentDefinition|null> */
    private array $definitions = [];
    /** @var array<string, string>|null */
    private ?array $replacements = null;

    public function __construct(private readonly string $buildDir)
    {
    }

    public function buildDir(): string
    {
        return $this->buildDir;
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

        if (Root::class === $fqcn) {
            // A built-in component: the Renderer renders it (see Phem\Root).
            return $this->definitions[$fqcn] = new ComponentDefinition($fqcn, ComponentKind::FunctionComponent, slots: ['children']);
        }

        if (Suspense::class === $fqcn) {
            // A built-in component: the SuspenseRenderer renders it (see Phem\Suspense).
            return $this->definitions[$fqcn] = new ComponentDefinition($fqcn, ComponentKind::ClassComponent, slots: ['children', 'fallback', 'error']);
        }

        if (ErrorBoundary::class === $fqcn) {
            // A built-in component: the ErrorBoundaryRenderer renders it (see Phem\ErrorBoundary).
            return $this->definitions[$fqcn] = new ComponentDefinition($fqcn, ComponentKind::ClassComponent, slots: ['children', 'fallback']);
        }

        $data = $this->data()['components'][$fqcn] ?? null;

        return $this->definitions[$fqcn] = null !== $data ? ComponentDefinition::fromArray($fqcn, $data) : null;
    }

    /** @return iterable<ComponentDefinition> */
    public function components(): iterable
    {
        foreach (array_keys($this->data()['components'] ?? []) as $fqcn) {
            if (null !== $definition = $this->component($fqcn)) {
                yield $definition;
            }
        }
    }

    /**
     * The component that replaces a component in the tags (`#[Component(replaces: ...)]`), or
     * null. phemc already resolves the tags of the `.phem` files: a Twig template of the app
     * uses it.
     */
    public function replacement(string $fqcn): ?string
    {
        if (null === $this->replacements) {
            $this->replacements = [];
            foreach ($this->data()['components'] ?? [] as $replacement => $def) {
                if (isset($def['replaces'])) {
                    $this->replacements[(string) $def['replaces']] = $replacement;
                }
            }
        }

        return $this->replacements[ltrim($fqcn, '\\')] ?? null;
    }

    /** @return list<string> all the page components: the components with a `#[Route]` attribute */
    public function pages(): array
    {
        return array_keys($this->data()['pages'] ?? []);
    }

    /**
     * The page components of one package.
     *
     * @param string $package the Composer package name (`acme/ui`), or `''` for the application
     *
     * @return list<string>
     */
    public function pagesOf(string $package): array
    {
        return array_keys(array_filter($this->data()['pages'] ?? [], static fn (string $of): bool => $of === $package));
    }

    /** @return list<string> the Composer package names that have pages, without the application */
    public function pagePackages(): array
    {
        return array_values(array_unique(array_filter($this->data()['pages'] ?? [], static fn (string $of): bool => '' !== $of)));
    }

    /** @return list<string> the template names, without the Twig namespace */
    public function templates(): array
    {
        return array_keys($this->data()['templates'] ?? []);
    }

    /**
     * A hash of what Twig reads from the manifest when it compiles `{% component %}`: the kind,
     * the template and the replaced component of each component. It is empty without a build.
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
            $parts[$fqcn] = [$def['kind'] ?? null, $def['template'] ?? null, $def['replaces'] ?? null];
        }

        ksort($parts);

        return $this->twigSignature = hash('xxh128', serialize($parts));
    }

    /** The Twig name of a compiled template: `@phemc/Page/index.0.twig`. */
    public static function templateName(string $template): string
    {
        return '@'.self::TEMPLATE_NAMESPACE.'/'.$template;
    }

    /**
     * The `.phem` source of a compiled template. The source is a path from the project root
     * (`src/Ui/HomePage.phem`, `vendor/acme/ui/components/Badge.phem`).
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

    /** @return ManifestData */
    private function data(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        if (!$this->exists()) {
            throw new \RuntimeException(\sprintf('No compiled project in "%s". Run `phemc build src` first.', $this->buildDir));
        }

        $data = require $this->file();

        // An older phemc wrote the pages as a list. The other values do not show the version.
        if ([] !== ($data['pages'] ?? []) && array_is_list($data['pages'])) {
            throw new \RuntimeException(\sprintf('The compiled project in "%s" comes from another version of phemc. Run `phemc build` again.', $this->buildDir));
        }

        require_once $this->buildDir.'/autoload.php';

        return $this->data = $data;
    }
}
