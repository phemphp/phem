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

namespace Phem\Tests\Fixtures;

use Phem\BrowserEventDispatcher;
use Phem\Reply;

/** A reply for a test that calls a handler or a middleware with no request. */
final class Replies
{
    /**
     * @param BrowserEventDispatcher $dispatcher the dispatcher that the test reads, or a new one
     */
    public static function make(BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher()): Reply
    {
        return new Reply($dispatcher);
    }
}
