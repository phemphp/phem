<?php

declare(strict_types=1);

namespace Mamu\Attribute;

/**
 * Runs each event handler of a class component in a database transaction (Doctrine ORM).
 *
 * When the handler returns, the entity manager flushes and commits: new entities (`persist()`),
 * removed entities (`remove()`) and the changes to managed entities. When the handler throws,
 * the transaction rolls back. The handler does not call `flush()`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Transactional
{
}
