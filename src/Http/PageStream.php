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

use Phem\Rendering\SuspenseStream;
use Phem\Rendering\Tree;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * The streamed response of a page with `<Suspense>` boundaries to load (see PageRenderer): the
 * page first, with no state, then a chunk for each boundary (see SuspenseStream), then the state,
 * before the end of the body.
 *
 * The boundaries render after the kernel sent the headers, so they render as in the request: the
 * request is in the request stack, and the locale is the locale of the request. The session
 * starts before the headers, and it is open while the boundaries render (see
 * Phem\EventListener\PageStreamListener). It is saved after them.
 *
 * When the render of the boundaries throws, the page ends with no state:
 *
 *     <template data-phem-root-state data-phem-stream-error></template>
 *
 * The `phem` controller does not start: the page has no events. The logger gets the exception,
 * and in debug, the exception goes on after the page.
 */
final class PageStream
{
    /** The request attribute of a page that streams (see Phem\EventListener\PageStreamListener). */
    public const ATTRIBUTE = '_phem_stream';

    public function __construct(
        private readonly SuspenseStream $boundaries,
        private readonly ?RequestStack $requests = null,
        private readonly ?LocaleAwareInterface $locales = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * @param string             $html  the HTML of the page, with `data-phem-streaming` on its root element
     * @param \Closure(): string $state the state attributes of the root element, after the boundaries
     */
    public function response(Tree $tree, Request $request, string $html, \Closure $state): StreamedResponse
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        // A boundary can need the session: its cookie goes with the headers.
        if ($request->hasSession()) {
            $request->getSession()->start();
        }

        // The chunks go before the end of the body.
        $end = strripos($html, '</body');
        [$shell, $tail] = false !== $end ? [substr($html, 0, $end), substr($html, $end)] : [$html, ''];

        return new StreamedResponse($this->chunks($tree, $request, $shell, $tail, $state), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // No buffer in nginx: each chunk goes to the browser at once.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param \Closure(): string $state
     *
     * @return \Generator<string>
     */
    private function chunks(Tree $tree, Request $request, string $shell, string $tail, \Closure $state): \Generator
    {
        yield $shell;

        // The kernel finished the request: the boundaries render as in it.
        $this->requests?->push($request);
        $locale = $this->locales?->getLocale();
        $this->locales?->setLocale($request->getLocale());
        $error = null;

        try {
            yield from $this->boundaries->chunks($tree, self::nonce($request, $shell));
            $end = '<template data-phem-root-state '.$state().'></template>';
        } catch (\Throwable $error) {
            $this->logger?->critical('[phem] The stream of the page "{page}" failed: the page has no events. '.$error->getMessage(), ['page' => $tree->page, 'exception' => $error]);
            $end = '<template data-phem-root-state data-phem-stream-error></template>';
        } finally {
            if ($request->hasSession(true) && $request->getSession()->isStarted()) {
                $request->getSession()->save();
            }

            if (null !== $locale) {
                $this->locales->setLocale($locale);
            }

            $this->requests?->pop();
        }

        yield $end.$tail;

        if (null !== $error && $this->debug) {
            throw $error;
        }
    }

    /**
     * The CSP nonce of the scripts of the page: the `csp_script_nonce` request attribute, or the
     * nonce of the first script of the page (the script of the importmap).
     */
    private static function nonce(Request $request, string $html): ?string
    {
        $nonce = $request->attributes->get('csp_script_nonce');
        if (\is_string($nonce) && '' !== $nonce) {
            return $nonce;
        }

        if (1 === preg_match('/<script\b[^>]*?\snonce=(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $html, $m)) {
            return html_entity_decode($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? ''), \ENT_QUOTES | \ENT_HTML5);
        }

        return null;
    }
}
