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

namespace Phem\Http;

use Phem\Rendering\Fiber;

/**
 * The Turbo Streams that morph the fibers that changed in the browser. Each stream replaces the
 * element with the fiber id (its root element or its `<phem-c>`), so a morph also changes the
 * attributes of the root element.
 */
final class TurboStreams
{
    /** @param iterable<Fiber> $fibers */
    public function updates(iterable $fibers): string
    {
        $streams = '';
        foreach ($fibers as $fiber) {
            $streams .= \sprintf('<turbo-stream action="replace" method="morph" targets="[data-phem-c=%s]"><template>%s</template></turbo-stream>', $fiber->id, $fiber->wrapped());
        }

        return $streams;
    }
}
