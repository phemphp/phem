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

namespace Mamu\Path;

use Mamu\Binding\ComponentName;

/**
 * An entity prop gets the entity of its route value from the EntityFinder (Doctrine ORM, when
 * DoctrineBundle is installed): an identifier goes to `find()`, and criteria (field => value,
 * from `{slug:post}`) go to `findOneBy()`.
 */
final class EntityPathConverter implements PathValueConverter
{
    public function __construct(private readonly ?EntityFinder $entities = null)
    {
    }

    public function supports(PathProp $prop): bool
    {
        return !$prop->type->isBuiltin() && null !== $this->entities && $this->entities->supports($prop->type->getName());
    }

    public function convert(PathProp $prop, mixed $value): ?object
    {
        $class = $prop->type->getName();
        if (!class_exists($class) && !interface_exists($class)) {
            throw new \LogicException(\sprintf('The type "%s" of the route value "%s" of <%s> is not a class.', $class, $prop->name, ComponentName::short($prop->page)));
        }
        $entity = null !== $value ? $this->entities?->find($class, $value) : null;
        if (null === $entity && !$prop->type->allowsNull()) {
            throw new \UnexpectedValueException(\sprintf('No %s matches the route value "%s" of <%s>.', ComponentName::short($class), $prop->name, ComponentName::short($prop->page)));
        }

        return $entity;
    }
}
