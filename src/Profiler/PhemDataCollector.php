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

namespace Phem\Profiler;

use Phem\Binding\ComponentName;
use Phem\Controller\EventController;
use Phem\Http\EventBody;
use Phem\Http\EventPayload;
use Phem\Rendering\Tree;
use Phem\State\StateAccessor;
use Phem\State\StateJson;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * The Phem panel of the Symfony profiler, for a page request and for an event: the handler,
 * the bound values, the component tree with the state and the render time of each component,
 * the components that changed, the size of the state, and the transaction.
 *
 * The data comes from the Profile, from the request and from the response. It does not change
 * what the request does.
 */
final class PhemDataCollector extends DataCollector
{
    public function __construct(
        private readonly Profile $profile,
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly EventBody $body = new EventBody(0),
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [];
        $page = $request->attributes->get('_phem_page');
        if (!\is_string($page) || '' === $page) {
            return;
        }

        $event = $request->isMethod('POST') && $request->headers->has(EventController::HEADER);
        $payload = $event ? $this->payload($request) : null;
        $answer = $event ? self::answer($response) : [];

        $trees = $this->profile->trees();
        $tree = null;
        foreach ($trees as $t) {
            if ($t->page === $page) {
                $tree = $t;
                break;
            }
        }

        $outcome = self::outcome($event, $response, $answer, $exception);

        $this->data = [
            'page' => $page,
            'event' => $event,
            'outcome' => $outcome,
            'redirect' => \is_string($answer['redirect'] ?? null) ? $answer['redirect'] : null,
            'status' => $response->getStatusCode(),
            'handler' => null !== $payload ? ['id' => [] !== $payload->handlers ? implode(', ', $payload->handlers) : null] + ($this->profile->handled() ?? []) : null,
            'live' => null !== $payload && [] === $payload->handlers,
            'bound' => null !== $payload ? $this->cloneVar($payload->bound) : null,
            'bound_count' => null !== $payload ? \count($payload->bound) : 0,
            'state_sent' => null !== $payload ? null !== $payload->state : null,
            'request_size' => $event ? \strlen((string) $request->getContent()) : null,
            'response_size' => \strlen((string) $response->getContent()),
            'browser_events' => $this->cloneVar(\is_array($answer['events'] ?? null) ? $answer['events'] : []),
            'browser_event_count' => \is_array($answer['events'] ?? null) ? \count($answer['events']) : 0,
            'warnings' => array_values(array_filter(\is_array($answer['warnings'] ?? null) ? $answer['warnings'] : [], 'is_string')),
            'transaction' => $this->profile->transacted(),
            'stale' => $payload->stale ?? [],
            'streams' => $this->profile->streams(),
            'passes' => $this->passes($trees, $tree, $event),
            'fibers' => [],
            'state' => null,
        ];

        if (null !== $tree && 'rendered' === $outcome) {
            $this->data['fibers'] = $this->fibers($tree, $trees, $event);
            // As StateCodec::encode(): the full state, in the page, and in an event that sends it.
            $json = StateJson::encode($this->state->snapshot($tree)->toArray());
            $this->data['state'] = ['size' => \strlen($json), 'gzip' => \strlen((string) gzencode($json))];
        }
    }

    public function getName(): string
    {
        return 'phem';
    }

    public function reset(): void
    {
        $this->data = [];
    }

    /** Whether the request was a page or an event of a page. */
    public function isPhem(): bool
    {
        return isset($this->data['page']);
    }

    public function getPage(): ?string
    {
        return $this->data['page'] ?? null;
    }

    public function getShortPage(): ?string
    {
        $page = $this->getPage();

        return null !== $page ? ComponentName::short($page) : null;
    }

    public function isEvent(): bool
    {
        return $this->data['event'] ?? false;
    }

    /** `rendered`, `redirect`, `reload`, `state_required`, `rate_limited` or `error`. */
    public function getOutcome(): ?string
    {
        return $this->data['outcome'] ?? null;
    }

    public function getRedirect(): ?string
    {
        return $this->data['redirect'] ?? null;
    }

    public function getStatus(): int
    {
        return $this->data['status'] ?? 0;
    }

    /** @return array{id: string|null, name?: string, file?: string|null, line?: int|null, time?: float, exception?: string|null}|null */
    public function getHandler(): ?array
    {
        return $this->data['handler'] ?? null;
    }

    /** Whether the event has only bound values (`phem:live`), with no handler. */
    public function isLive(): bool
    {
        return $this->data['live'] ?? false;
    }

    public function getBound(): ?Data
    {
        return $this->data['bound'] ?? null;
    }

    public function getBoundCount(): int
    {
        return $this->data['bound_count'] ?? 0;
    }

    /** Whether the browser sent the full state (null for a page): it did not, when the StateCache had it. */
    public function isStateSent(): ?bool
    {
        return $this->data['state_sent'] ?? null;
    }

    public function getRequestSize(): ?int
    {
        return $this->data['request_size'] ?? null;
    }

    public function getResponseSize(): int
    {
        return $this->data['response_size'] ?? 0;
    }

    public function getBrowserEvents(): ?Data
    {
        return $this->data['browser_events'] ?? null;
    }

    public function getBrowserEventCount(): int
    {
        return $this->data['browser_event_count'] ?? 0;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->data['warnings'] ?? [];
    }

    /** @return list<string> the components whose element a push changed, that the event rendered again */
    public function getStale(): array
    {
        return $this->data['stale'] ?? [];
    }

    /** @return list<array{topics: list<string>, names: list<string>, data: string, private: bool}> the updates that the request published to the Mercure hub */
    public function getStreams(): array
    {
        return $this->data['streams'] ?? [];
    }

    /** @return array{committed: bool, time: float}|null */
    public function getTransaction(): ?array
    {
        return $this->data['transaction'] ?? null;
    }

    /** @return list<array{label: string, main: bool, pass: int, fibers: int, time: float}> */
    public function getPasses(): array
    {
        return $this->data['passes'] ?? [];
    }

    /**
     * The passes of the page tree: the columns of the component table.
     *
     * @return list<array{label: string, main: bool, pass: int, fibers: int, time: float}>
     */
    public function getMainPasses(): array
    {
        return array_values(array_filter($this->getPasses(), static fn (array $p): bool => $p['main']));
    }

    /** The render time of all the passes, in milliseconds. */
    public function getRenderTime(): float
    {
        return array_sum(array_column($this->getPasses(), 'time'));
    }

    /**
     * The fibers of the page, from the page down.
     *
     * @return list<array{id: string, fqcn: string|null, name: string|null, depth: int, renders: array<int, array{time: float, own: float}>, changed: bool, state: Data|null, reads: array<string, string|null>, provides: list<string>, propagated: bool}>
     */
    public function getFibers(): array
    {
        return $this->data['fibers'] ?? [];
    }

    /** The number of fibers that rendered in the last pass of the page tree. */
    public function getRenderedCount(): int
    {
        $passes = $this->getMainPasses();

        return [] !== $passes ? $passes[\count($passes) - 1]['fibers'] : 0;
    }

    public function getChangedCount(): int
    {
        return \count(array_filter($this->getFibers(), static fn (array $f): bool => $f['changed']));
    }

    /** @return array{size: int, gzip: int}|null the size of the signed JSON of the state, and its gzip size */
    public function getState(): ?array
    {
        return $this->data['state'] ?? null;
    }

    private function payload(Request $request): ?EventPayload
    {
        try {
            return EventPayload::fromJson($this->body->json($request));
        } catch (\Throwable) {
            // The controller rejected the event already: the panel shows the response.
            return null;
        }
    }

    /** @return array<string, mixed> the JSON of an event response */
    private static function answer(Response $response): array
    {
        $content = $response->getContent();
        if (false === $content || !str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $answer */
    private static function outcome(bool $event, Response $response, array $answer, ?\Throwable $exception): string
    {
        if (429 === $response->getStatusCode()) {
            return 'rate_limited';
        }

        if (null !== $exception || (!$response->isSuccessful() && 409 !== $response->getStatusCode())) {
            return 'error';
        }

        if (!$event) {
            return 'rendered';
        }

        return match (true) {
            'required' === ($answer['state'] ?? null) => 'state_required',
            true === ($answer['reload'] ?? null) => 'reload',
            isset($answer['redirect']) => 'redirect',
            isset($answer['streams']) => 'rendered',
            default => 'error',
        };
    }

    /**
     * The render passes of the request: the passes of the page tree, then the full render of the
     * dev-mode check (see SkippedRenderCheck), and the components of plain Twig templates.
     *
     * @param list<Tree> $trees
     *
     * @return list<array{label: string, main: bool, pass: int, fibers: int, time: float}>
     */
    private function passes(array $trees, ?Tree $main, bool $event): array
    {
        $out = [];
        foreach ($trees as $tree) {
            $passes = [];
            foreach ($this->profile->renders($tree) as $render) {
                $passes[$render['pass']] ??= ['fibers' => 0, 'time' => 0.0];
                ++$passes[$render['pass']]['fibers'];
                if (0 === $render['depth']) {
                    $passes[$render['pass']]['time'] += $render['time'];
                }
            }

            ksort($passes);
            foreach ($passes as $pass => $data) {
                $label = match (true) {
                    $tree !== $main => '' === $tree->page ? 'Twig template' : 'Dev-mode check',
                    !$event => 'Render',
                    1 === $pass => 'Before the handler',
                    2 === $pass => 'After the handler',
                    default => 'Pass '.$pass,
                };
                $out[] = ['label' => $label, 'main' => $tree === $main, 'pass' => $pass] + $data;
            }
        }

        return $out;
    }

    /**
     * @param list<Tree> $trees
     *
     * @return list<array{id: string, fqcn: string|null, name: string|null, depth: int, renders: array<int, array{time: float, own: float}>, changed: bool, state: Data|null, reads: array<string, string|null>, provides: list<string>, propagated: bool}>
     */
    private function fibers(Tree $tree, array $trees, bool $event): array
    {
        // A fiber that did not render in the page tree has its name from the dev-mode check.
        $names = [];
        foreach ($trees as $t) {
            foreach ($this->profile->renders($t) as $render) {
                $names[$render['fiber']] ??= $render['fqcn'];
            }
        }

        $renders = [];
        foreach ($this->profile->renders($tree) as $render) {
            $renders[$render['fiber']][$render['pass']] = ['time' => $render['time'], 'own' => $render['own']];
        }

        $changed = $event ? array_fill_keys(array_map(static fn ($f): string => $f->id, $tree->changedFibers()), true) : [];
        $states = $this->state->snapshot($tree)->fibers;

        $out = [];
        $depths = [];
        foreach ($tree->page() as $id => [$parent, $rendered]) {
            $depth = $depths[$id] = null !== $parent ? ($depths[$parent] ?? -1) + 1 : 0;
            $fqcn = $names[$id] ?? null;

            // The contexts of the last render of the fiber in this request (see ContextResolver).
            $fiber = $tree->fiberById($id);
            $reads = [];
            foreach ($rendered->contexts ?? [] as $type => $provider) {
                $providerFqcn = $tree->fiberById($provider)->fqcn ?? null;
                $reads[ComponentName::short($type)] = null !== $providerFqcn ? ComponentName::short($providerFqcn) : null;
            }

            $out[] = [
                'id' => $id,
                'fqcn' => $fqcn,
                'name' => null !== $fqcn ? ComponentName::short($fqcn) : null,
                'depth' => $depth,
                'renders' => $renders[$id] ?? [],
                'changed' => isset($changed[$id]),
                'state' => isset($states[$id]) ? $this->cloneVar($states[$id]) : null,
                'reads' => $reads,
                'provides' => null !== $fiber && $fiber->providedPass > 0 ? array_map(ComponentName::short(...), array_keys($fiber->provided)) : [],
                'propagated' => $event && null !== $rendered && $rendered->propagated,
            ];
        }

        return $out;
    }
}
