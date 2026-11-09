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

/**
 * An entity that a flush inserts, updates or deletes (see EntityRefreshes).
 */
final class EntityChange
{
    /**
     * @param bool         $updated false for an insert or a delete
     * @param list<string> $fields  the changed fields, associations and collections of an update
     * @param list<object> $related the entities of its to-one associations, also the old entity of a changed association
     */
    public function __construct(
        public readonly object $entity,
        public readonly bool $updated = false,
        public readonly array $fields = [],
        public readonly array $related = [],
    ) {
    }
}
