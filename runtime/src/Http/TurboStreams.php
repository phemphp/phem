<?php

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
            $streams .= \sprintf('<turbo-stream action="update" method="morph" target="%s"><template>%s</template></turbo-stream>', $fiber->id, Title::strip($fiber->html));
        }

        return $streams;
    }
}
