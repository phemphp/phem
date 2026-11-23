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

/** The handlers that ran, by name, for the tests of the handlers of disabled elements. */
final class HandlerCalls
{
    /** @var list<string> */
    public static array $calls = [];
}
