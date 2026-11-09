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

namespace Phem\Stream;

use Phem\Metadata\ComponentMetadata;

/**
 * The refreshes of an entity change for the pages and the components with
 * #[Broadcast(on: ...)] (see Phem\Attribute\Broadcast). A Doctrine listener gives it the changes
 * of a flush (see Phem\Doctrine\EntityBroadcastListener).
 *
 * A page or a component gets a refresh when the entity is an instance of a class of its `on`
 * (a Doctrine proxy too), and, for an update with a field list, when one of these fields changed:
 *
 * - A page refreshes the URLs where a #[Path] prop has the entity, or one of its related
 *   entities (`$line->order` for a `#[Path] Order $order`). With no such prop, all its URLs.
 * - A component refreshes the instances where a #[BroadcastKey] prop has the entity or a related
 *   entity. With no such prop, all its instances.
 *
 * of() returns the targets and queues nothing: the listener refreshes them after the flush. A
 * deleted entity loses a generated identifier in the flush, so the topics come before it.
 */
final class EntityRefreshes
{
    /** @var array<class-string, list<array{0: string, 1: list<string>}>> entity class => [the page or the component, its fields] */
    private array $matches = [];

    /**
     * @param list<string> $broadcasters the pages and the components with #[Broadcast(on: ...)]
     */
    public function __construct(
        private readonly Streams $streams,
        private readonly Topics $topics,
        private readonly BroadcastKeys $keys,
        private readonly array $broadcasters = [],
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    /**
     * The entities of each page and component with #[Broadcast(on: ...)], with their fields.
     *
     * @return array<string, array<class-string, list<string>>>
     */
    public function entities(): array
    {
        $entities = [];
        foreach ($this->broadcasters as $name) {
            $entities[$name] = $this->metadata->entityBroadcasts($name);
        }

        return $entities;
    }

    /** Whether a page or a component has a class of `on` that the entity is an instance of. */
    public function watches(object $entity): bool
    {
        return [] !== $this->matches($entity);
    }

    /**
     * The targets of the refreshes of a change, once each.
     *
     * @return list<PageStreams>
     */
    public function of(EntityChange $change): array
    {
        $targets = [];
        foreach ($this->matches($change->entity) as [$name, $fields]) {
            // An insert and a delete change all the fields.
            if ($change->updated && [] !== $fields && [] === array_intersect($fields, $change->fields)) {
                continue;
            }

            foreach ($this->targets($name, [$change->entity, ...$change->related]) as $target) {
                $targets[$target->topic.' '.$target->key] = $target;
            }
        }

        return array_values($targets);
    }

    /**
     * The pages and the components with a class of `on` that the entity is an instance of, with
     * their fields (none: all the fields).
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function matches(object $entity): array
    {
        if (isset($this->matches[$entity::class])) {
            return $this->matches[$entity::class];
        }

        $matches = [];
        foreach ($this->broadcasters as $name) {
            $fields = null;
            foreach ($this->metadata->entityBroadcasts($name) as $class => $of) {
                if ($entity instanceof $class) {
                    $fields = self::merge($fields, $of);
                }
            }

            if (null !== $fields) {
                $matches[] = [$name, $fields];
            }
        }

        return $this->matches[$entity::class] = $matches;
    }

    /**
     * The URLs of a page, or the instances of a component, that show one of the entities.
     *
     * @param list<object> $entities the entity of the change, and its related entities
     *
     * @return list<PageStreams>
     */
    private function targets(string $name, array $entities): array
    {
        $component = $this->metadata->isComponent($name);

        // The route values of a page: a function page also gets services and the current user.
        $props = $component ? $this->metadata->broadcastKeyTypes($name) : $this->topics->paths($name);

        $targets = [];
        foreach ($props as $prop => $type) {
            foreach ($entities as $entity) {
                if (self::fits($type, $entity)) {
                    $targets[] = $component
                        ? new PageStreams($this->streams, $this->topics->toComponent($name), $this->keys->ofProp($name, $prop, $entity))
                        : new PageStreams($this->streams, $this->topics->toPageProp($name, $prop, $entity));
                }
            }
        }

        if ([] !== $targets) {
            return $targets;
        }

        return [$component
            ? new PageStreams($this->streams, $this->topics->toComponent($name), $this->keys->ofComponent($name))
            : new PageStreams($this->streams, $this->topics->toPage($name)),
        ];
    }

    /**
     * The fields of two classes of `on` that the entity is an instance of: a class with no
     * fields refreshes for all the fields.
     *
     * @param list<string>|null $fields the fields of the classes before, or null for none
     * @param list<string>      $of
     *
     * @return list<string>
     */
    private static function merge(?array $fields, array $of): array
    {
        if (null === $fields) {
            return $of;
        }

        if ([] === $fields || [] === $of) {
            return [];
        }

        return array_values(array_unique([...$fields, ...$of]));
    }

    /** Whether a prop of this type can have the entity: a class type of the entity, also in a union. */
    private static function fits(?\ReflectionType $type, object $entity): bool
    {
        return match (true) {
            $type instanceof \ReflectionNamedType => !$type->isBuiltin() && is_a($entity, $type->getName()),
            $type instanceof \ReflectionUnionType => array_any($type->getTypes(), static fn (\ReflectionType $t): bool => self::fits($t, $entity)),
            default => false,
        };
    }
}
