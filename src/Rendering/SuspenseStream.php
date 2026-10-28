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

namespace Phem\Rendering;

use Phem\Dom\Attributes;
use Phem\Suspense;
use Phem\SuspenseStatus;

/**
 * Renders the `<Suspense>` boundaries of a page after the page, in the response of the page, as
 * React streams its boundaries. The page renders their fallbacks, and the response sends the page
 * first (see Phem\Http\PageStream). Then each boundary loads and renders in the same pass, and
 * its chunk replaces its fallback in the browser:
 *
 *     <template data-phem-for="phem…">the children</template><script>…</script>
 *
 * The small script replaces the element of the boundary (its fiber id) with the template, while
 * the page loads. When the CSP of the page blocks the script, the `phem` controller replaces it
 * when the page starts. A boundary in the children of a boundary goes to the end of the queue.
 *
 * When the children throw and the boundary has no error slot, the boundary sends no chunk: its
 * fallback stays, and the browser loads it with an event (see SuspenseRenderer).
 */
final class SuspenseStream
{
    /**
     * Replaces the element of the boundary with the template before the script. The scripts of the
     * template run, as in the page: a copy of each one runs when it goes in the page.
     */
    private const SWAP = '(function(t){var e=document.querySelector(\'[data-phem-c="\'+t.getAttribute("data-phem-for")+\'"]\');if(e){t.content.querySelectorAll("script").forEach(function(s){var n=document.createElement("script");for(var i=0;i<s.attributes.length;i++)n.setAttribute(s.attributes[i].name,s.attributes[i].value);n.nonce=s.nonce;n.textContent=s.textContent;s.replaceWith(n)});e.replaceWith(t.content);t.remove()}document.currentScript.remove()})(document.currentScript.previousElementSibling)';

    public function __construct(
        private readonly Renderer $renderer,
    ) {
    }

    /**
     * The chunks of the boundaries that wait in the queue of the tree (see Tree::defer()).
     *
     * @param string|null $nonce the CSP nonce of the scripts of the page
     *
     * @return \Generator<string>
     */
    public function chunks(Tree $tree, ?string $nonce = null): \Generator
    {
        $script = '<script'.(null !== $nonce ? ' '.Attributes::attr('nonce', $nonce) : '').'>'.self::SWAP.'</script>';

        while (null !== $fiber = $tree->nextDeferred()) {
            $suspense = $fiber->instance;
            if (!$suspense instanceof Suspense) {
                continue;
            }

            $suspense->load();
            $this->renderer->resume($tree, $fiber);

            if (SuspenseStatus::Pending === $suspense->status) {
                continue;
            }

            yield '<template data-phem-for="'.$fiber->id.'">'.$fiber->wrapped().'</template>'.$script;
        }
    }
}
