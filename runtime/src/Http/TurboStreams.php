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

namespace Mamu\Http;

use Mamu\Rendering\Fiber;

/**
 * The Turbo Streams that morph the fibers that changed in the browser.
 */
final class TurboStreams
{
    /** @param iterable<Fiber> $fibers */
    public function updates(iterable $fibers): string
    {
        $streams = '';
        foreach ($fibers as $fiber) {
            $streams .= \sprintf('<turbo-stream action="update" method="morph" target="%s"><template>%s</template></turbo-stream>', $fiber->id, $fiber->html);
        }

        return $streams;
    }
}
