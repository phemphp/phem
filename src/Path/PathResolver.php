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

namespace Phem\Path;

use Phem\Binding\ComponentName;
use Phem\Binding\PropError;
use Phem\Metadata\ComponentMetadata;

/**
 * The props of a page from its route values: the values of the #[Path] properties (the
 * parameters of a function page), converted to their types by the PathValueConverters.
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

        $what = class_exists($page) ? 'The #[Path] prop' : 'The route value parameter';

        throw new PropError(\sprintf('%s "%s" of <%s> has the type %s, which is not a Doctrine entity. A route value is an int, float, bool, string, or a Doctrine entity (install doctrine/orm and doctrine/doctrine-bundle).', $what, $name, ComponentName::short($page), $type->getName()));
    }
}
