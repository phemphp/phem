<?php

declare(strict_types=1);

namespace Rephp;

use Rephp\Runtime\Renderer;
use Rephp\Server\Request;
use Rephp\Server\RequestHandler;
use Rephp\Server\Response;

/**
 * The HTTP application: server-side rendering of pages, and `POST /_rhp/event` for events.
 *
 * Client state: the `rhp` Stimulus controller on the page root keeps the state of all
 * components (a signed JSON value). An event sends it back with the handler id. The server
 * renders with that state, runs the handler, renders again, and answers with Turbo Streams
 * that morph the components that changed, plus the new state.
 */
final class App implements RequestHandler
{
    public const EVENT_PATH = '/_rhp/event';

    private const ACTIONS = ['click', 'dblclick', 'input', 'change', 'submit', 'keydown', 'keyup', 'focusin', 'focusout'];

    private readonly StateCodec $codec;

    public function __construct(private readonly Runtime $runtime, ?StateCodec $codec = null)
    {
        $this->codec = $codec ?? StateCodec::fromEnvironment($runtime->cacheDir);
    }

    public function handle(Request $request): ?Response
    {
        try {
            if (self::EVENT_PATH === $request->path) {
                return 'POST' === $request->method ? $this->event($request) : Response::text("Method Not Allowed\n", 405);
            }
            if ('GET' !== $request->method && 'HEAD' !== $request->method) {
                return null;
            }
            $page = $this->runtime->route($request->path);

            return null === $page ? null : Response::html($this->page($request->path, $page));
        } catch (\Throwable $e) {
            if (!$this->runtime->debug) {
                throw $e;
            }

            return Response::html(ErrorPage::render($e, $this->runtime), 500);
        }
    }

    /** Renders a full HTML document for a page. */
    public function page(string $path, string $fqcn): string
    {
        $route = '/'.trim($path, '/');
        $renderer = new Renderer($this->runtime);
        $html = $renderer->render($fqcn);
        [$json, $signature] = $this->codec->encode($renderer->exportState(), $route);

        return $this->document($route, $html, $json, $signature);
    }

    private function event(Request $request): Response
    {
        $data = json_decode($request->body, true);
        if (!\is_array($data) || !\is_string($data['route'] ?? null) || !\is_string($data['handler'] ?? null)) {
            return Response::text("Bad Request\n", 400);
        }
        $route = '/'.trim($data['route'], '/');
        $page = $this->runtime->route($route);
        $state = $this->codec->decode((string) ($data['state'] ?? '{}'), (string) ($data['signature'] ?? ''), $route);
        if (null === $page || null === $state) {
            return self::json(['reload' => true], 409);
        }

        $renderer = new Renderer($this->runtime, $state);
        $renderer->render($page);
        $handler = $renderer->handler($data['handler']);
        if (null === $handler) {
            // The page changed since the browser rendered it (for example after a deploy).
            return self::json(['reload' => true], 409);
        }
        $renderer->run($handler, Event::fromArray(\is_array($data['event'] ?? null) ? $data['event'] : []));
        $renderer->render($page);

        $streams = '';
        foreach ($renderer->changedFibers() as $fiber) {
            $html = preg_replace('#<title>.*?</title>#s', '', $fiber->html, 1);
            $streams .= \sprintf('<turbo-stream action="update" method="morph" target="%s"><template>%s</template></turbo-stream>', $fiber->id, $html);
        }
        [$json, $signature] = $this->codec->encode($renderer->exportState(), $route);

        return self::json(['streams' => $streams, 'state' => $json, 'signature' => $signature]);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function document(string $route, string $html, string $json, string $signature): string
    {
        // React 19 style: a <title> anywhere in the page goes into <head>.
        $title = 'rephp';
        if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
            $title = trim($m[1]);
            $html = str_replace($m[0], '', $html);
        }
        $e = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $css = is_file($this->runtime->projectDir.'/public/app.css') ? "\n<link rel=\"stylesheet\" href=\"/app.css\">" : '';
        $actions = implode(' ', array_map(static fn ($a) => $a.'->rhp#dispatch', self::ACTIONS));

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <link rel="icon" href="data:,">
            <style>rh-c{display:contents}</style>{$css}
            <script type="module" src="/_rhp/assets/rhp.js"></script>
            </head>
            <body>
            <div id="rh-root" data-controller="rhp" data-action="{$actions}" data-rhp-route-value="{$e($route)}" data-rhp-endpoint-value="{$e(self::EVENT_PATH)}" data-rhp-state-value="{$e($json)}" data-rhp-signature-value="{$e($signature)}">{$html}</div>
            </body>
            </html>

            HTML;
    }
}
