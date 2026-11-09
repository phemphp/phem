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

namespace Phem\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ManagerRegistry;
use Phem\Stream\EntityChange;
use Phem\Stream\EntityRefreshes;
use Phem\Stream\PageStreams;
use Phem\Stream\SenderToken;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Refreshes the pages and the components with #[Broadcast(on: ...)] after each flush of the
 * Doctrine ORM that inserts, updates or deletes their entities (see Phem\Stream\EntityRefreshes).
 *
 * - onFlush reads the unit of work. An update has its changed fields, and its changed
 *   collections. A deleted entity loses a generated identifier in the flush, so its targets come
 *   here. An inserted entity has no generated identifier yet: its targets come after the flush.
 *   The listener has a low priority, so the changes of the other onFlush listeners are there.
 * - postFlush refreshes the targets. The streams go to the hub at the end of the request (see
 *   Phem\Stream\Streams), and a handler that throws drops them (see
 *   Phem\Handler\StreamsMiddleware). A flush that throws has no postFlush: its targets go.
 * - Each entity manager has its own flushes, and a flush can start in the postFlush of another
 *   one (an audit log): each flush keeps its own targets.
 * - A flush in the render of a push refresh (an event with no handler) refreshes nothing: a
 *   render that writes a watched entity (a "last seen" date) would make two browsers refresh
 *   each other with no end.
 * - A DQL UPDATE or DELETE, or SQL, is not in the unit of work: it refreshes nothing.
 */
final class EntityBroadcastListener implements ResetInterface
{
    /** @var array<int, list<array{0: list<PageStreams>, 1: list<object>}>> entity manager => its flushes: [targets, inserted entities] */
    private array $flushes = [];
    private bool $checked = false;

    public function __construct(
        private readonly EntityRefreshes $refreshes,
        private readonly ManagerRegistry $doctrine,
        private readonly SenderToken $sender = new SenderToken(),
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->check();

        $manager = $args->getObjectManager();
        $uow = $manager->getUnitOfWork();

        // The changed fields of each updated entity, with its changed collections.
        $updates = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->refreshes->watches($entity)) {
                $updates[spl_object_id($entity)] = [$entity, array_keys($uow->getEntityChangeSet($entity))];
            }
        }

        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if (null !== $owner && $this->refreshes->watches($owner) && !$uow->isScheduledForInsert($owner) && !$uow->isScheduledForDelete($owner)) {
                $updates[spl_object_id($owner)] ??= [$owner, []];
                $updates[spl_object_id($owner)][1][] = self::field($collection);
            }
        }

        $targets = [];
        foreach ($updates as [$entity, $fields]) {
            $targets = [...$targets, ...$this->refreshes->of(new EntityChange($entity, true, $fields, $this->related($manager, $entity, $uow->getEntityChangeSet($entity))))];
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->refreshes->watches($entity)) {
                $targets = [...$targets, ...$this->refreshes->of(new EntityChange($entity, related: $this->related($manager, $entity)))];
            }
        }

        $inserted = array_values(array_filter($uow->getScheduledEntityInsertions(), $this->refreshes->watches(...)));
        $this->flushes[spl_object_id($manager)][] = [$targets, $inserted];
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $id = spl_object_id($manager);

        // The last flush of this entity manager: a flush of its postFlush listeners ended before.
        $flush = isset($this->flushes[$id]) ? array_pop($this->flushes[$id]) : null;
        if ([] === ($this->flushes[$id] ?? null)) {
            unset($this->flushes[$id]);
        }

        if (null === $flush || $this->sender->isRefresh()) {
            return;
        }

        [$targets, $inserted] = $flush;

        foreach ($inserted as $entity) {
            $targets = [...$targets, ...$this->refreshes->of(new EntityChange($entity, related: $this->related($manager, $entity)))];
        }

        foreach ($targets as $target) {
            $target->refresh();
        }
    }

    public function reset(): void
    {
        $this->flushes = [];
    }

    /**
     * The entities of the to-one associations of an entity, and the old entity of a changed
     * association: the page of the old order refreshes too when a line moves to another order. A
     * new entity of the same flush has no identifier yet, and no page shows it: it is not there.
     *
     * @param array<string, mixed> $changes the change set of an update: [old, new] for a field or a to-one association
     *
     * @return list<object>
     */
    private function related(EntityManagerInterface $manager, object $entity, array $changes = []): array
    {
        $metadata = $manager->getClassMetadata($entity::class);

        $related = [];
        foreach (array_keys($metadata->getAssociationMappings()) as $field) {
            if (!$metadata->isSingleValuedAssociation($field)) {
                continue;
            }

            $old = \is_array($changes[$field] ?? null) ? $changes[$field][0] ?? null : null;

            foreach ([$metadata->getFieldValue($entity, $field), $old] as $value) {
                if (\is_object($value) && !self::isNew($manager, $value) && !\in_array($value, $related, true)) {
                    $related[] = $value;
                }
            }
        }

        return $related;
    }

    /** Whether the entity has no identifier yet: an insert of this flush, before the SQL. */
    private static function isNew(EntityManagerInterface $manager, object $entity): bool
    {
        return [] === $manager->getClassMetadata($entity::class)->getIdentifierValues($entity);
    }

    /**
     * The field of a collection in its owner.
     *
     * @param PersistentCollection<array-key, object> $collection
     */
    private static function field(PersistentCollection $collection): string
    {
        // An array in ORM 2, an AssociationMapping with array access in ORM 3.
        return $collection->getMapping()['fieldName'];
    }

    /**
     * The classes and the fields of #[Broadcast(on: ...)] are entities and their fields, once,
     * with the entity manager of each class.
     *
     * @throws \LogicException when a class is not an entity, has no such field, or the field is the inverse side of an association
     */
    private function check(): void
    {
        if ($this->checked) {
            return;
        }

        foreach ($this->refreshes->entities() as $name => $entities) {
            foreach ($entities as $class => $fields) {
                // An interface has no mapping: the entities that implement it have their own fields.
                if (interface_exists($class)) {
                    continue;
                }

                $manager = $this->doctrine->getManagerForClass($class);
                if (!$manager instanceof EntityManagerInterface || $manager->getMetadataFactory()->isTransient($class)) {
                    throw new \LogicException(\sprintf('"%s" has #[Broadcast(on: ...)] with the class "%s", which is not a Doctrine entity.', $name, $class));
                }

                $metadata = $manager->getClassMetadata($class);

                foreach ($fields as $field) {
                    self::checkField($metadata, $field, $name);
                }
            }
        }

        $this->checked = true;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @throws \LogicException when the entity has no such field, or the field is the inverse side of an association
     */
    private static function checkField(ClassMetadata $metadata, string $field, string $name): void
    {
        if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
            throw new \LogicException(\sprintf('"%s" has #[Broadcast(on: ...)] with the field "%s" of "%s", but the entity has no such field or association.', $name, $field, $metadata->getName()));
        }

        // A flush does not write the inverse side (mappedBy): it never changes.
        if ($metadata->hasAssociation($field) && $metadata->isAssociationInverseSide($field)) {
            throw new \LogicException(\sprintf('"%s" has #[Broadcast(on: ...)] with the field "%s" of "%s", but it is the inverse side of an association, which a flush never changes. Put the entity of the owning side in "on" (for example its class with no fields).', $name, $field, $metadata->getName()));
        }
    }
}
