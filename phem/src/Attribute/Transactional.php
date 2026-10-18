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

namespace Phem\Attribute;

/**
 * Runs each event handler of a class component in a database transaction (Doctrine ORM).
 *
 * When the handler returns, the entity manager flushes and commits: new entities (`persist()`),
 * removed entities (`remove()`) and the changes to managed entities. When the handler throws,
 * the transaction rolls back. The handler does not call `flush()`.
 *
 * When Doctrine ORM is enabled, the `phem.transactional` option runs the handlers of all
 * components in a transaction, so this attribute is necessary only when the option is false.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Transactional
{
}
