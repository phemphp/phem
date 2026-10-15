<?php

declare(strict_types=1);

namespace Rephp\Runtime;

use Rephp\Attribute\State;
use Twig\Template;

/**
 * One component instance in the render tree. It keeps the hook slots (or the class instance)
 * and the HTML of the last render passes.
 *
 * The id comes from the position in the tree (parent, component, index or key), so it is the
 * same in each request: the browser keeps the state by fiber id.
 *
 * @internal
 */
final class Fiber
{
    public ?Fiber $parent = null;

    /** @var array<string, mixed> */
    public array $props = [];
    public ?Template $slots = null;
    /** @var array<string, mixed> */
    public array $caller = [];

    /** Hook slots: index → value (state, reducer state, ref, memo). @var array<int, mixed> */
    public array $hooks = [];
    /** @var array<int, string> */
    public array $hookTypes = [];
    public int $hookIndex = 0;
    public ?int $hookCount = null;
    /** @var array<int, \Closure> */
    public array $setters = [];
    /** @var array<int, \Closure> */
    public array $reducers = [];

    public ?object $instance = null;

    /** @var array<int, mixed> spl_object_id(Context) → value */
    public array $contextValues = [];
    /** @var list<Fiber> */
    public array $children = [];
    /** @var array<string, int> */
    private array $childIndexes = [];
    public int $handlerIndex = 0;

    public int $pass = 0;
    /** The inner HTML of the last render. */
    public string $html = '';
    /** The HTML without the child fibers' content, by pass. @var array<int, string> */
    public array $ownHtml = [];

    /**
     * @param array<string, mixed> $restored the state that the browser sent back
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $fqcn,
        public readonly string $kind,
        public readonly array $restored = [],
    ) {
    }

    public static function idOf(string $path): string
    {
        return 'rh'.substr(hash('xxh128', $path), 0, 12);
    }

    public function begin(int $pass): void
    {
        $this->pass = $pass;
        $this->hookIndex = 0;
        $this->handlerIndex = 0;
        $this->children = [];
        $this->childIndexes = [];
        $this->contextValues = [];
    }

    public function nextIndex(string $fqcn): int
    {
        $this->childIndexes[$fqcn] ??= 0;

        return $this->childIndexes[$fqcn]++;
    }

    /** Claims the next hook slot and checks the order of hooks. */
    public function hook(string $type): int
    {
        $i = $this->hookIndex++;
        if (isset($this->hookTypes[$i]) && $this->hookTypes[$i] !== $type) {
            throw new HookError(\sprintf('The order of hooks changed in <%s>: hook #%d was %s, now it is %s. Call hooks at the top level only (Rules of Hooks).', $this->name(), $i + 1, $this->hookTypes[$i], $type));
        }
        $this->hookTypes[$i] = $type;

        return $i;
    }

    public function end(): void
    {
        if (null !== $this->hookCount && $this->hookCount !== $this->hookIndex) {
            throw new HookError(\sprintf('<%s> rendered %d hooks, but %d in the previous render. Call hooks at the top level only (Rules of Hooks).', $this->name(), $this->hookIndex, $this->hookCount));
        }
        $this->hookCount = $this->hookIndex;
    }

    public function name(): string
    {
        return substr(strrchr('\\'.$this->fqcn, '\\'), 1);
    }

    public function wrapped(): string
    {
        return '<rh-c id="'.$this->id.'">'.$this->html.'</rh-c>';
    }

    /**
     * The state to send to the browser: hook state (useState, useReducer, useRef) or the
     * #[State] properties of a class.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $out = [];
        if (null !== $this->instance) {
            foreach (self::stateProperties($this->instance::class) as $prop) {
                if ($prop->isInitialized($this->instance)) {
                    $out['c'][$prop->getName()] = $prop->getValue($this->instance);
                }
            }
        }
        foreach ($this->hookTypes as $i => $type) {
            if ('state' === $type || 'reducer' === $type) {
                $out['h'][$i] = $this->hooks[$i];
            } elseif ('ref' === $type && self::isJson($this->hooks[$i]->current)) {
                $out['h'][$i] = $this->hooks[$i]->current;
            }
        }

        return $out;
    }

    private static function isJson(mixed $v): bool
    {
        if (null === $v || \is_scalar($v)) {
            return true;
        }
        if (\is_array($v)) {
            foreach ($v as $item) {
                if (!self::isJson($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** @return list<\ReflectionProperty> */
    public static function stateProperties(string $class): array
    {
        static $cache = [];

        return $cache[$class] ??= array_values(array_filter(
            (new \ReflectionClass($class))->getProperties(),
            static fn (\ReflectionProperty $p) => !$p->isStatic() && [] !== $p->getAttributes(State::class),
        ));
    }
}
