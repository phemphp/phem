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

namespace Phem;

/**
 * The status of a `<Suspense>` boundary, in the page state.
 */
enum SuspenseStatus: string
{
    /** The fallback shows: the browser did not load the children yet. */
    case Pending = 'pending';
    /** The children show. */
    case Loaded = 'loaded';
    /** The render of the children threw: the error slot shows. */
    case Failed = 'failed';
}
