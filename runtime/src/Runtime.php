<?php

declare(strict_types=1);

namespace Rephp;

use Rephp\Twig\RephpExtension;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The runtime of a compiled project: the manifest that rhpc wrote, and the Twig environment.
 *
 * Layout (all paths relative to the project directory):
 *   src/                   .rhp sources
 *   var/cache/rhp/         rhpc output: php/, templates/, manifest.php, autoload.php, preload.php
 *   var/cache/twig/        Twig cache (compiled by `rephp warmup`, at build time)
 *   public/                static files
 */
final class Runtime
{
    private static ?self $instance = null;

    private ?Environment $twig = null;

    /** @var array<string, mixed> */
    public readonly array $manifest;

    public function __construct(
        public readonly string $projectDir,
        public readonly string $cacheDir,
        public readonly bool $debug = false,
    ) {
        $manifestFile = $cacheDir.'/manifest.php';
        if (!is_file($manifestFile)) {
            throw new \RuntimeException(\sprintf('No compiled project in "%s". Run `rhpc build src` first.', $cacheDir));
        }
        $this->manifest = require $manifestFile;
        require_once $cacheDir.'/autoload.php';
    }

    /**
     * Boots the runtime once (from the environment: RHP_ROOT, RHP_CACHE, RHP_DEBUG).
     */
    public static function boot(?string $cacheDir = null, ?string $projectDir = null): self
    {
        if (null !== self::$instance && (null === $cacheDir || self::$instance->cacheDir === self::abs($cacheDir))) {
            return self::$instance;
        }
        $projectDir = self::abs($projectDir ?? (getenv('RHP_ROOT') ?: getcwd()));
        $cacheDir = self::abs($cacheDir ?? (getenv('RHP_CACHE') ?: $projectDir.'/var/cache/rhp'), $projectDir);
        $debug = filter_var(getenv('RHP_DEBUG') ?: '0', \FILTER_VALIDATE_BOOL);

        return self::$instance = new self($projectDir, $cacheDir, $debug);
    }

    public static function get(): self
    {
        return self::$instance ?? self::boot();
    }

    /** For tests. */
    public static function set(?self $runtime): void
    {
        self::$instance = $runtime;
    }

    private static function abs(string $path, ?string $base = null): string
    {
        if (!str_starts_with($path, '/')) {
            $path = ($base ?? getcwd()).'/'.$path;
        }

        return rtrim(realpath($path) ?: $path, '/');
    }

    public function isProd(): bool
    {
        return (bool) ($this->manifest['prod'] ?? false);
    }

    public function twigCacheDir(): string
    {
        return \dirname($this->cacheDir).'/twig';
    }

    public function twig(): Environment
    {
        if (null !== $this->twig) {
            return $this->twig;
        }
        $loader = new FilesystemLoader([$this->cacheDir.'/templates']);
        $loader->addPath(\dirname(__DIR__).'/resources/twig', 'rephp');
        $twig = new Environment($loader, [
            'cache' => new FilesystemCache($this->twigCacheDir(), FilesystemCache::FORCE_BYTECODE_INVALIDATION),
            'auto_reload' => !$this->isProd(),
            'strict_variables' => true,
            'autoescape' => 'html',
            'debug' => $this->debug,
        ]);
        $twig->addExtension(new RephpExtension($this));

        return $this->twig = $twig;
    }

    /** @return array<string, mixed>|null */
    public function component(string $fqcn): ?array
    {
        return $this->manifest['components'][ltrim($fqcn, '\\')] ?? null;
    }

    /** The page component of a URL path, or null. */
    public function route(string $path): ?string
    {
        $path = '/'.trim($path, '/');

        return $this->manifest['routes'][$path] ?? null;
    }

    /**
     * Compiles every template into the Twig cache. `rhpc build` runs it, so no template is
     * parsed at runtime.
     *
     * @return int the number of templates
     */
    public function warmup(): int
    {
        $twig = $this->twig();
        $n = 0;
        foreach (array_keys($this->manifest['templates'] ?? []) as $name) {
            $twig->load($name);
            ++$n;
        }
        $twig->load('@rephp/slot_host.twig');

        return $n;
    }

    /**
     * OPcache preload: loads the Twig cache classes (the components are already required by
     * autoload.php). `require_once` (not `opcache_compile_file()`) autoloads their parent
     * class, so OPcache can link them.
     */
    public static function preload(string $cacheDir): void
    {
        $twigDir = \dirname($cacheDir).'/twig';
        if (!is_dir($twigDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($twigDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('php' === $file->getExtension()) {
                require_once $file->getPathname();
            }
        }
    }
}
