<?php

declare(strict_types=1);

namespace Mamu\Path;

use Mamu\Binding\ComponentName;
use Mamu\Binding\PropError;
use Mamu\Metadata\ComponentMetadata;

/**
 * The props of a page from its route values: the values of the #[Path] properties or
 * parameters, converted to their types by the PathValueConverters.
 */
final class PathResolver
{
    /**
     * @param iterable<PathValueConverter> $converters
     */
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly iterable $converters = [new ScalarPathConverter(), new EntityPathConverter()],
    ) {
    }

    /**
     * @param array<string, mixed> $values the route values
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException when a value does not match its type (`abc` for `int $id`), or no entity matches it
     */
    public function resolve(string $page, array $values): array
    {
        $out = [];
        foreach ($this->metadata->pathProps($page) as $name => $type) {
            if (\array_key_exists($name, $values)) {
                $out[$name] = $this->value($page, $name, $type, $values[$name]);
            }
        }

        return $out;
    }

    private function value(string $page, string $name, ?\ReflectionType $type, mixed $value): mixed
    {
        if (!$type instanceof \ReflectionNamedType || \is_object($value)) {
            return $value;
        }
        $prop = new PathProp($page, $name, $type);
        foreach ($this->converters as $converter) {
            if ($converter->supports($prop)) {
                return $converter->convert($prop, $value);
            }
        }

        throw new PropError(\sprintf('The #[Path] prop "%s" of <%s> has the type %s, which is not a Doctrine entity. A #[Path] prop is an int, float, bool, string, or a Doctrine entity (install doctrine/orm and doctrine/doctrine-bundle).', $name, ComponentName::short($page), $type->getName()));
    }
}
