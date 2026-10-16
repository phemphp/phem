<?php

declare(strict_types=1);

namespace Rephp\Controller;

use Rephp\Event;
use Rephp\Manifest;
use Rephp\Rendering\PropBinder;
use Rephp\Rendering\Renderer;
use Rephp\Rendering\Tree;
use Rephp\StateCodec;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `POST /_rhp/event`: runs an event handler.
 *
 * The `rhp` Stimulus controller sends the signed state of the page and the handler id. The
 * server renders with that state, runs the handler, renders again, and answers with Turbo
 * Streams that morph the components that changed, plus the new state. When the handler returns
 * a RedirectResponse, the server answers with its URL, and the browser visits it.
 */
final class EventController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly Manifest $manifest,
        private readonly PropBinder $props = new PropBinder(),
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['page'] ?? null) || !\is_string($data['handler'] ?? null)) {
            throw new BadRequestHttpException('An event needs a page and a handler.');
        }
        $page = $data['page'];
        $state = $this->codec->decode((string) ($data['state'] ?? '{}'), (string) ($data['signature'] ?? ''), $page);
        if (null === $state || !\in_array($page, $this->manifest->pages(), true)) {
            // The state is not valid any more (a new build, or a new secret).
            return new JsonResponse(['reload' => true], 409);
        }

        // The route values of the page are in the signed state. The entities come from them again.
        $values = \is_array($state['_path'] ?? null) ? $state['_path'] : [];
        unset($state['_path']);
        try {
            $props = $this->props->pathValues($page, $values);
        } catch (\UnexpectedValueException) {
            // An entity of the page is not there any more: the page shows a 404 response.
            return new JsonResponse(['reload' => true], 409);
        }
        $tree = new Tree($page, $state, $props, $values);
        $this->renderer->render($tree);
        $event = Event::fromArray(\is_array($data['event'] ?? null) ? $data['event'] : [], $request);
        if (!$this->renderer->dispatch($tree, $data['handler'], $event)) {
            // The page changed since the browser rendered it (for example after a deploy).
            return new JsonResponse(['reload' => true], 409);
        }
        if (null !== $tree->redirect) {
            // The browser visits the URL: the page does not render again.
            return new JsonResponse(['redirect' => $tree->redirect]);
        }
        $this->renderer->render($tree);

        $streams = '';
        foreach ($tree->changedFibers() as $fiber) {
            $html = preg_replace('#<title>.*?</title>#s', '', $fiber->html, 1);
            $streams .= \sprintf('<turbo-stream action="update" method="morph" target="%s"><template>%s</template></turbo-stream>', $fiber->id, $html);
        }
        [$json, $signature] = $this->codec->encode($this->renderer->exportState($tree), $page);

        return new JsonResponse(['streams' => $streams, 'state' => $json, 'signature' => $signature]);
    }
}
