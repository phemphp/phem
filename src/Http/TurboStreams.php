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
use Phem\Rendering\KeptRuns;
use Phem\State\TreeShape;

/**
 * The Turbo Streams that morph the fibers that changed in the browser. Each stream replaces the
 * element with the fiber id (its root element or its `<phem-c>`), so a morph also changes the
 * attributes of the root element. A run of kept fibers has one placeholder (see KeptRuns).
 */
final class TurboStreams
{
    public function __construct(
        private readonly KeptRuns $runs = new KeptRuns(),
    ) {
    }

    /**
     * @param iterable<Fiber> $fibers
     * @param TreeShape       $before the shape of the render before the event, in the browser
     */
    public function updates(iterable $fibers, TreeShape $before = new TreeShape()): string
    {
        $streams = '';
        foreach ($fibers as $fiber) {
            $streams .= \sprintf('<turbo-stream action="replace" method="morph" targets="[data-phem-c=%s]"><template>%s</template></turbo-stream>', $fiber->id, $this->runs->collapse($fiber->wrapped(), $before));
        }

        return $streams;
    }
}
