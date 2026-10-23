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
 * Runs event handlers in a database transaction (Doctrine ORM).
 *
 * On a class component, each event handler of the component runs in a transaction: its methods
 * and the closures of `render()`. On a method, only this handler runs in a transaction.
 *
 * When the handler returns, the entity manager flushes and commits: new entities (`persist()`),
 * removed entities (`remove()`) and the changes to managed entities. When the handler throws,
 * the transaction rolls back. The handler does not call `flush()`.
 *
 * The other handlers run without a transaction.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Transactional
{
}
