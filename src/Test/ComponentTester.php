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

namespace Phem\Test;

use Dom\Element;
use Dom\HTMLDocument;
use Phem\Form\TouchedFields;
use Phem\Stream\Stream;
use Phem\Test\Internal\Driver;
use Phem\Test\Internal\EventResult;
use Phem\Test\Internal\StreamTopics;
use PHPUnit\Framework\Assert;

/**
 * A rendered component or page in a test, with the actions of a user and assertions.
 *
 *     $this->mount(Counter::class, ['label' => 'Clicks'])
 *         ->click('button')
 *         ->assertSee('Clicks 1 times')
 *         ->assertState('count', 1);
 *
 * The tester keeps the HTML in a DOM, as a browser does. An action finds the element with a
 * CSS selector, and sends the event as the `phem` Stimulus controller does: the handler of the
 * element or of its closest ancestor, the value of the element, and the fields of a form. The
 * answer morphs the components that changed. An action fails when no handler gets the event,
 * or when the element is disabled: a browser would do nothing.
 *
 * Get one from InteractsWithPhem: mount() for a component, visit() for a page.
 */
final class ComponentTester
{
    /** The DOM events that send the fields of the form of their element. */
    private const FORM_EVENTS = ['submit', 'change', 'input', 'click'];

    private const TEXT_INPUTS = ['text', 'search', 'email', 'url', 'tel', 'password', 'number', 'date', 'time', 'datetime-local', 'month', 'week', 'color', 'range'];

    private HTMLDocument $document;
    private int $status;
    private ?string $redirect;
    private bool $reload = false;
    /** @var list<array{name: string, detail: mixed}> */
    private array $dispatched = [];
    /**
     * The bound fields (`phem:bind`) that the user changed, as the browser keeps them until the
     * next event: key => the fields of the key, with a function that gives back the server value.
     *
     * @var array<string, list<array{0: Element, 1: \Closure(): void}>>
     */
    private array $edited = [];
    /**
     * The files that the user selected in the file inputs: input => paths. As in a browser, the
     * files belong to the element: the morph keeps them when it keeps the element (see
     * moveFiles()), and a handler that calls `$e->clearFiles()` empties them.
     *
     * @var \SplObjectStorage<Element, list<string>>
     */
    private \SplObjectStorage $selected;
    /**
     * The components whose element a push changed: the next event sends them (see receive()).
     *
     * @var array<string, true>
     */
    private array $stale = [];
    /**
     * The tokens of the events of this page (see Phem\Stream\SenderToken): a refresh after one of
     * them skips the component of its handler.
     *
     * @var list<string>
     */
    private array $sent = [];
    /** The number of the published updates that the page saw. */
    private int $seen;

    /**
     * @param \Closure(string): self|null                                      $open    opens a URL in the browser of the page (for visit() and followRedirect())
     * @param \Closure(): list<array{topics: list<string>, data: string}>|null $updates the published updates (see Phem\Stream\MemoryPublisher)
     * @param \Closure(): int|null                                             $settle  runs the waiting work of the #[Async] handlers, and returns its number (see Phem\Async\MemoryBus)
     *
     * @internal use InteractsWithPhem::mount() or InteractsWithPhem::visit()
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly ?\Closure $open = null,
        private readonly ?\Closure $updates = null,
        private readonly ?\Closure $settle = null,
    ) {
        // The page gets the updates that come after its render.
        $this->seen = null !== $updates ? \count($updates()) : 0;
        $this->selected = new \SplObjectStorage();

        $html = $driver->html();
        $this->document = HTMLDocument::createFromString($driver->isDocument() ? $html : '<!DOCTYPE html><html><head></head><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');

        $this->status = $driver->status();
        $this->redirect = $driver->redirect();
    }

    // --- Actions ---------------------------------------------------------------------------

    /**
     * Clicks an element (`onClick`). A submit button also submits its form (`onSubmit`), with
     * the button as a field.
     */
    public function click(string $selector): self
    {
        $element = $this->enabled($selector);
        $form = self::formOf($element);

        // The browser makes both events before the answer of the first one.
        $events = array_filter([$this->event($element, 'click'), null !== $form ? $this->event($form, 'submit', $element) : null]);
        if ([] === $events) {
            Assert::fail(\sprintf('The element "%s" has no onClick handler, and it does not submit a form with an onSubmit handler.', $selector));
        }

        return $this->send(...$events);
    }

    public function doubleClick(string $selector): self
    {
        return $this->fire($selector, 'dblclick');
    }

    /**
     * Changes the value of a field (`onChange`, `onInput`), as a user does: types a text, checks a
     * checkbox (true or false) or a radio button (true, or the value of a button of its group),
     * or selects options (a value, or a list of values for `<select multiple>`).
     *
     * As in React, `onChange` of a text field gets each change, not only the last one.
     *
     * @param string|int|float|bool|list<string> $value
     */
    public function change(string $selector, string|int|float|bool|array $value): self
    {
        $element = $this->setValue($this->enabled($selector), $value, $selector);

        // A text field sends `input` (for onInput and onChange), other fields `input` and `change`.
        $types = self::isTextLike($element) ? ['input'] : ['input', 'change'];
        $events = array_filter(array_map(fn (string $type): ?array => $this->event($element, $type), $types));
        if ([] === $events && $element->hasAttribute('data-phem-live') && $element->hasAttribute('data-phem-bind')) {
            // `phem:live`: an event with no handler, with the bound values.
            $events = [[null, ['type' => self::isTextLike($element) ? 'input' : 'change', 'live' => true]]];
        }

        if ([] === $events) {
            Assert::fail(\sprintf('The element "%s" has no onChange or onInput handler, and no phem:live.', $selector));
        }

        return $this->send(...$events);
    }

    /**
     * Changes the value of a field, as change() does, but sends no event. For a bound field
     * (`phem:bind`), the next event sends the new value, as in a browser:
     *
     *     ->fill('input[name=query]', 'Ada')->click('button')->assertState('query', 'Ada')
     *
     * @param string|int|float|bool|list<string> $value
     */
    public function fill(string $selector, string|int|float|bool|array $value): self
    {
        $this->setValue($this->enabled($selector), $value, $selector);

        return $this;
    }

    /**
     * Selects files in a file input (`<input type="file">`), as a user does, but sends no event.
     * The next submit of its form sends them, and the handler gets them in `$e->files`:
     *
     *     ->upload('input[name=photo]', __DIR__.'/ada.jpg')->submit()->assertSee('ada.jpg')
     *
     * The handler gets a copy of each file, so it can move it. An empty list removes the files.
     *
     * @param string|list<string> $paths more than one path for `<input type="file" multiple>`
     */
    public function upload(string $selector, string|array $paths): self
    {
        $element = $this->enabled($selector);
        if ('input' !== $element->localName || 'file' !== strtolower($element->getAttribute('type') ?? '')) {
            Assert::fail(\sprintf('The element "%s" is not a file input.', $selector));
        }

        $paths = (array) $paths;
        if (\count($paths) > 1 && !$element->hasAttribute('multiple')) {
            Assert::fail(\sprintf('The file input "%s" has no "multiple" attribute: it takes one file only.', $selector));
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                Assert::fail(\sprintf('The file "%s" does not exist.', $path));
            }
        }

        $this->selected[$element] = $paths;

        return $this;
    }

    /**
     * Submits a form (`onSubmit`). The selector is the form, or the button that submits it.
     * The fields get the values first, as change() does: field name => value.
     *
     * @param array<string, string|int|float|bool|list<string>> $fields
     */
    public function submit(string $selector = 'form', array $fields = []): self
    {
        $element = $this->enabled($selector);
        $submitter = 'form' === $element->localName ? null : $element;
        $form = null === $submitter ? $element : self::formOf($submitter);
        if (null === $form) {
            Assert::fail(\sprintf('The element "%s" is not a form or a button that submits a form.', $selector));
        }

        foreach ($fields as $name => $value) {
            $this->fillField($form, $name, $value);
        }

        $event = $this->event($form, 'submit', $submitter) ?? Assert::fail(\sprintf('The form "%s" has no onSubmit handler.', $selector));

        return $this->send($event);
    }

    public function keyDown(string $selector, string $key): self
    {
        return $this->fire($selector, 'keydown', ['key' => $key]);
    }

    public function keyUp(string $selector, string $key): self
    {
        return $this->fire($selector, 'keyup', ['key' => $key]);
    }

    public function focus(string $selector): self
    {
        return $this->fire($selector, 'focusin');
    }

    public function blur(string $selector): self
    {
        return $this->fire($selector, 'focusout');
    }

    /**
     * Sends a DOM event to the handler of an element (`dblclick` for `onDoubleClick`).
     *
     * @param array<string, mixed> $event values that replace the values of the element (value, checked, key, form)
     */
    public function fire(string $selector, string $type, array $event = []): self
    {
        $data = $this->event($this->enabled($selector), $type) ?? Assert::fail(\sprintf('The element "%s" has no handler for the "%s" event.', $selector, $type));
        $data[1] = $event + $data[1];

        return $this->send($data);
    }

    /**
     * Loads the `<Suspense>` boundaries that show their fallback, as the browser does when `lazy`
     * boundaries come into view, or when the stream of a boundary failed: one load event for all
     * of them. The page shows the other boundaries as at the end of its stream. A
     * selector loads only one boundary: the boundary of the element (an element in its fallback,
     * or the boundary). Loaded children can have boundaries too: call load() again.
     *
     *     ->assertSee('Loading the report')->load()->assertSee('Rows 3')
     */
    public function load(?string $selector = null): self
    {
        if (null !== $selector) {
            $element = $this->document->querySelector($selector) ?? Assert::fail(\sprintf('No element matches "%s". %s', $selector, $this->context()));
            $boundaries = [$element->closest('[data-phem-load]') ?? Assert::fail(\sprintf('The element "%s" is not in a <Suspense> that shows its fallback.', $selector))];
        } else {
            $boundaries = iterator_to_array($this->document->querySelectorAll('[data-phem-load]'), false);
            if ([] === $boundaries) {
                Assert::fail(\sprintf('The page has no <Suspense> that shows its fallback. %s', $this->context()));
            }
        }

        $event = ['type' => 'load', 'value' => null, 'checked' => null, 'key' => null, 'form' => null];

        return $this->send([array_map(static fn (Element $boundary): string => (string) $boundary->getAttribute('data-phem-load'), $boundaries), $event]);
    }

    /**
     * Gets the pushes of other requests (see Phem\Stream\Streams), as the `phem` Stimulus
     * controller does: the updates of the topics of the page since its render or the last
     * receive(). A stream in the root element marks the component around its target as stale, and
     * the next event sends it. A `refresh()` sends one event with no handler. Before an `append`
     * or a `prepend`, an element of the target with the id of a new element is removed. A page
     * with #[Broadcast], or with a component with #[Broadcast], only:
     *
     *     $other = $this->visit('/guestbook');
     *     $this->visit('/guestbook')->fill('textarea', 'Hi')->click('button');
     *     $other->receive()->assertSee('Hi');
     */
    public function receive(): self
    {
        $source = $this->document->querySelector('[data-phem-stream-value]');
        if (null === $source || null === $this->updates) {
            Assert::fail(\sprintf('The page has no #[Broadcast]: it gets no pushes. %s', $this->context()));
        }

        $topics = StreamTopics::of((string) $source->getAttribute('data-phem-stream-value'));

        $updates = ($this->updates)();
        $refresh = false;
        foreach (\array_slice($updates, $this->seen) as $update) {
            if ([] !== array_intersect($update['topics'], $topics)) {
                $refresh = $this->push($update['data']) || $refresh;
            }
        }

        $this->seen = \count($updates);
        $this->dispatched = [];

        if ($refresh && [] !== $this->stale) {
            return $this->send([null, ['type' => 'refresh', 'value' => null, 'checked' => null, 'key' => null, 'form' => null, 'refresh' => true]]);
        }

        return $this;
    }

    /**
     * Runs the work of the #[Async] handlers that wait, as a Messenger worker does (see
     * Phem\Attribute\Async): the work of all the pages of the test, in their order. The work
     * pushes its result: get it with receive(). A page only:
     *
     *     $this->visit('/export')
     *         ->click('button')->assertSee('Pending')
     *         ->settle()->receive()->assertSee('Download');
     */
    public function settle(): self
    {
        if (null === $this->settle) {
            Assert::fail('No #[Async] work can run here: install symfony/messenger, and test a page with visit().');
        }

        if (0 === ($this->settle)()) {
            Assert::fail(\sprintf('No #[Async] work waits: no handler returned a Promise. %s', $this->context()));
        }

        return $this;
    }

    /**
     * Opens a URL in the browser of this page, as a new tab (a page only). The pages of one
     * browser share its cookies (the session) and its server values, and each page gets its
     * pushes apart:
     *
     *     $staff = $this->visit('/login')->submit('form', [...])->followRedirect();
     *     $orders = $staff->visit('/admin/orders');
     */
    public function visit(string $uri): self
    {
        if (null === $this->open) {
            Assert::fail('A mounted component has no browser: use InteractsWithPhem::visit() to test a page.');
        }

        return ($this->open)($uri);
    }

    /** Opens the URL of the last redirect (a page only). */
    public function followRedirect(): self
    {
        if (null === $this->redirect) {
            Assert::fail('The last response is not a redirect.');
        }

        return $this->visit($this->redirect);
    }

    // --- Values ----------------------------------------------------------------------------

    /** The HTML: the document of a page, or the HTML of a component. */
    public function html(): string
    {
        $body = $this->document->body;

        return $this->driver->isDocument() || null === $body ? $this->document->saveHtml() : $body->innerHTML;
    }

    /** The text in the body, with the white space of the HTML as one space. */
    public function text(): string
    {
        return self::normalize($this->document->body->textContent ?? '');
    }

    /** The DOM of the HTML. Change it only as a user does. */
    public function document(): HTMLDocument
    {
        return $this->document;
    }

    /**
     * The #[State] properties of the page, or of the mounted component, as the browser keeps
     * them (JSON values). With `$in`, the properties of the component around the element that
     * matches this CSS selector (see assertState()).
     *
     * @return array<string, mixed>
     */
    public function state(?string $in = null): array
    {
        return $this->driver->state(null !== $in ? $this->fiberOf($in) : null);
    }

    /**
     * The URL that the browser shows: the URL of the page (or "/" for a component), with the
     * query of the #[Url] properties after the last action.
     */
    public function url(): string
    {
        return $this->driver->url();
    }

    // --- Assertions ------------------------------------------------------------------------

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString(self::normalize($text), $this->text(), $this->context());

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString(self::normalize($text), $this->text(), $this->context());

        return $this;
    }

    public function assertSeeHtml(string $html): self
    {
        Assert::assertStringContainsString($html, $this->html(), $this->context());

        return $this;
    }

    public function assertDontSeeHtml(string $html): self
    {
        Assert::assertStringNotContainsString($html, $this->html(), $this->context());

        return $this;
    }

    public function assertSelectorExists(string $selector): self
    {
        Assert::assertNotNull($this->document->querySelector($selector), \sprintf('No element matches "%s". %s', $selector, $this->context()));

        return $this;
    }

    public function assertSelectorNotExists(string $selector): self
    {
        Assert::assertNull($this->document->querySelector($selector), \sprintf('An element matches "%s". %s', $selector, $this->context()));

        return $this;
    }

    public function assertSelectorCount(int $count, string $selector): self
    {
        Assert::assertCount($count, $this->document->querySelectorAll($selector), \sprintf('The number of elements that match "%s". %s', $selector, $this->context()));

        return $this;
    }

    public function assertSelectorTextContains(string $selector, string $text): self
    {
        $element = $this->document->querySelector($selector) ?? Assert::fail(\sprintf('No element matches "%s". %s', $selector, $this->context()));
        Assert::assertStringContainsString(self::normalize($text), self::normalize($element->textContent ?? ''), \sprintf('The text of "%s".', $selector));

        return $this;
    }

    /**
     * Asserts the value of a #[State] property of the page, or of the mounted component.
     *
     * With `$in`, the property is of a child component: the closest component with state around
     * the element that matches this CSS selector, as in the DOM of the browser. With no such
     * component, it is the page, or the mounted component.
     *
     *     ->assertState('on', true, in: '#toggle')
     */
    public function assertState(string $name, mixed $expected, ?string $in = null): self
    {
        $state = $this->state($in);
        $of = null !== $in ? \sprintf(' of the component around "%s"', $in) : '';

        Assert::assertArrayHasKey($name, $state, \sprintf('The state%s has no "%s" property.', $of, $name));
        Assert::assertSame($expected, $state[$name], \sprintf('The "%s" state property%s.', $name, $of));

        return $this;
    }

    /**
     * Asserts a value in the query string of the URL that the browser shows (see url()). A list
     * is `key[]=a&key[]=b`. Null asserts that the URL has no such key.
     *
     *     ->assertQuery('q', 'php')
     *
     * @param string|array<array-key, mixed>|null $expected a list for `key[]=a`, an array with keys for `key[a]=1`
     */
    public function assertQuery(string $key, string|array|null $expected): self
    {
        parse_str((string) parse_url($this->url(), \PHP_URL_QUERY), $query);
        Assert::assertSame($expected, $query[$key] ?? null, \sprintf('The "%s" key in the query string of "%s".', $key, $this->url()));

        return $this;
    }

    /** Asserts the HTTP status of the last response (the page, or the last event). */
    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status, 'The status of the last response.');

        return $this;
    }

    /**
     * Asserts that the last response is a redirect: a handler that returns a Phem\RedirectTo or
     * a RedirectResponse, or a page that redirects.
     */
    public function assertRedirect(?string $url = null): self
    {
        Assert::assertNotNull($this->redirect, 'The last response is not a redirect.');

        if (null !== $url) {
            Assert::assertSame($url, $this->redirect, 'The URL of the redirect.');
        }

        return $this;
    }

    public function assertNoRedirect(): self
    {
        Assert::assertNull($this->redirect, \sprintf('The last response is a redirect to "%s".', $this->redirect));

        return $this;
    }

    /**
     * Asserts that the browser loads the page again after the last event (409): the state is not
     * valid any more, or the handler is not on the page any more.
     */
    public function assertReload(): self
    {
        Assert::assertTrue($this->reload, 'The last event does not reload the page.');

        return $this;
    }

    /**
     * Asserts that the last action sent a browser event (see Phem\BrowserEventDispatcher). With
     * `$detail`, an event with this name has this detail, as JSON gives it to the browser.
     *
     *     ->assertDispatched('toast', ['message' => 'Saved'])
     */
    public function assertDispatched(string $name, mixed $detail = null): self
    {
        $details = array_column(array_filter($this->dispatched, static fn (array $e): bool => $name === $e['name']), 'detail');
        Assert::assertNotEmpty($details, \sprintf('The last action did not dispatch the browser event "%s"%s.', $name, $this->dispatchedNames()));

        if (\func_num_args() > 1) {
            Assert::assertContains($detail, $details, \sprintf('The detail of the browser event "%s".', $name));
        }

        return $this;
    }

    public function assertNotDispatched(string $name): self
    {
        Assert::assertNotContains($name, array_column($this->dispatched, 'name'), \sprintf('The last action dispatched the browser event "%s".', $name));

        return $this;
    }

    // --- The browser -----------------------------------------------------------------------

    /**
     * Sends events in their order, as the queue of the `phem` Stimulus controller does, and
     * morphs the components that changed.
     *
     * Each event sends the bound fields that changed. After the morph, a field that the morph
     * did not render again gets the server value back, as in a browser: the server did not take
     * its value, or the value is the same.
     *
     * @param array{0: string|list<string>|null, 1: array<string, mixed>, 2?: list<Element>} ...$events handler id (null for `phem:live`, a list for a load of many boundaries), DOM event, and the file inputs whose files it sends
     */
    private function send(array ...$events): self
    {
        $this->dispatched = [];

        foreach ($events as $data) {
            [$handler, $event] = $data;
            $bound = array_map(self::boundValue(...), array_map(static fn (array $fields): array => array_column($fields, 0), $this->edited));
            $stale = array_keys($this->stale);
            $result = $this->driver->dispatch(null === $handler ? [] : (array) $handler, $event, $bound, $stale);
            $this->status = $result->status;
            $this->redirect = $result->redirect;
            $this->reload = $result->reload;

            if (200 !== $result->status || null !== $result->redirect) {
                // The browser leaves the page, or the event failed: the next events do not run,
                // and the browser events of the action are lost.
                $this->dispatched = [];
                break;
            }

            $this->stale = array_diff_key($this->stale, array_flip($stale));

            if (null !== $result->sender) {
                $this->sent[] = $result->sender;
            }

            // As in the browser, the handler took the files: their inputs are empty.
            if ($result->clearFiles) {
                foreach ($data[2] ?? [] as $input) {
                    unset($this->selected[$input]);
                }
            }

            $this->morph($result);

            foreach ($this->edited as $fields) {
                foreach ($fields as [$field, $restore]) {
                    if ($field->isConnected) {
                        $restore();
                    }
                }
            }

            $this->edited = [];
            array_push($this->dispatched, ...$result->dispatched);
        }

        return $this;
    }

    /**
     * Renders the Turbo Streams of a push (see receive()). Returns whether a stream is a refresh
     * of a component of the page.
     */
    private function push(string $data): bool
    {
        $message = HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>'.$data.'</body></html>', \LIBXML_NOERROR, 'UTF-8');
        $refresh = false;
        foreach ($message->querySelectorAll('turbo-stream') as $stream) {
            $action = (string) $stream->getAttribute('action');
            if (Stream::REFRESH === $action) {
                $refresh = $this->markRefresh($stream) || $refresh;
                continue;
            }

            $target = $this->document->getElementById((string) $stream->getAttribute('target'));
            if (null === $target) {
                continue;
            }

            $template = $stream->querySelector('template');
            $outside = \in_array($action, ['remove', 'replace', 'before', 'after'], true);
            $nodes = null !== $template ? $this->nodes($template->innerHTML, $outside ? $target->parentElement : $target) : [];

            $this->markStale($outside ? $target->parentElement : $target);

            // A morph moves the files of the inputs that it keeps. The other actions put new
            // elements in the page: their inputs have no files.
            $inputs = self::positions(self::fileInputsIn($target));

            if ('append' === $action || 'prepend' === $action) {
                foreach ($nodes as $node) {
                    $old = $node instanceof Element && '' !== $node->id ? $this->document->getElementById($node->id) : null;
                    if (null !== $old && $target->contains($old)) {
                        $old->remove();
                    }
                }
            }

            match ($action) {
                'append' => $target->append(...$nodes),
                'prepend' => $target->prepend(...$nodes),
                'before' => $target->before(...$nodes),
                'after' => $target->after(...$nodes),
                'replace' => $target->replaceWith(...$nodes),
                'update' => $target->replaceChildren(...$nodes),
                'remove' => $target->remove(),
                default => Assert::fail(\sprintf('A push has the Turbo Stream action "%s", which the tests do not know.', $action)),
            };

            $this->moveFiles($inputs, 'morph' === $stream->getAttribute('method') ? $nodes : []);
        }

        return $refresh;
    }

    /**
     * Marks the components of a refresh as stale: the component around the target, the components
     * of the `targets` selector, or the content of the page for a refresh with neither. As in the
     * browser, a refresh after an event of this page skips the component of its handler.
     */
    private function markRefresh(Element $stream): bool
    {
        $selector = $stream->getAttribute('targets');
        if (null === $selector) {
            return $stream->hasAttribute('target')
                ? $this->markStale($this->document->getElementById((string) $stream->getAttribute('target')))
                : $this->markStale($this->document->querySelector('[data-phem-state-value] [data-phem-c]'));
        }

        [$token, $skip] = explode(' ', (string) $stream->getAttribute('data-phem-skip'), 2) + [1 => ''];
        $own = '' !== $token && \in_array($token, $this->sent, true);

        $refresh = false;
        foreach ($this->document->querySelectorAll($selector) as $component) {
            if (!$own || $component->getAttribute('data-phem-c') !== $skip) {
                $refresh = $this->markStale($component) || $refresh;
            }
        }

        return $refresh;
    }

    /** Marks the component around an element as stale. Returns false when the element is not in a component. */
    private function markStale(?Element $element): bool
    {
        $component = $element?->closest('[data-phem-c]');
        if (null === $component) {
            return false;
        }

        $this->stale[(string) $component->getAttribute('data-phem-c')] = true;

        return true;
    }

    /**
     * The nodes of an HTML fragment, in the document of the page. The HTML parses as the content of
     * its parent element: a `<tr>` in a `<tbody>`, an `<option>` in a `<select>`.
     *
     * @return list<\Dom\Node>
     */
    private function nodes(string $html, ?Element $parent): array
    {
        $container = $this->document->createElement($parent->localName ?? 'div');
        $container->innerHTML = $html;

        return iterator_to_array($container->childNodes, false);
    }

    private function morph(EventResult $result): void
    {
        foreach ($result->updates as $id => $html) {
            $element = $this->component($id);
            if (null === $element) {
                Assert::fail(\sprintf('The event updates the component "%s", but the page has no element with this fiber id.', $id));
            }

            // A component that did not render again is a placeholder: as in the browser, the
            // morph keeps its element.
            $kept = [];
            preg_match_all('/<template data-phem-keep="([^"]+)"><\/template>/', $html, $ids);
            foreach ($ids[1] as $kid) {
                if (null !== $old = $this->component($kid)) {
                    $kept[$kid] = $old;
                }
            }

            // As in the browser, an element with `data-phem-ignore` in the page and in the new
            // HTML stays as it is (the tests find it by its id).
            $ignored = [];
            foreach ($element->querySelectorAll('[data-phem-ignore][id]') as $old) {
                $ignored[$old->id] = $old;
            }

            // As in the browser, a touched password field that the server renders with no value
            // (a PasswordType) keeps its value.
            $passwords = [];
            foreach ($element->querySelectorAll('input[type="password"][name][value]') as $old) {
                $passwords[(string) $old->getAttribute('name')] = (string) $old->getAttribute('value');
            }

            // As in the browser, the morph moves the files of the inputs that it keeps.
            $inputs = self::positions(self::fileInputsIn($element));

            // The new HTML is the element with the fiber id: it replaces the element of the page.
            $nodes = $this->nodes($html, $element->parentElement);
            $element->replaceWith(...$nodes);

            foreach ($nodes as $node) {
                if (!$node instanceof Element) {
                    continue;
                }

                foreach ($node->querySelectorAll('input[type="password"]['.TouchedFields::MARK.'][name]:not([value])') as $new) {
                    $value = $passwords[(string) $new->getAttribute('name')] ?? '';
                    if ('' !== $value) {
                        $new->setAttribute('value', $value);
                    }
                }

                foreach ($node->querySelectorAll('[data-phem-ignore][id]') as $new) {
                    if (null !== $old = $ignored[$new->id] ?? null) {
                        $new->replaceWith($old);
                    }
                }

                foreach ($node->querySelectorAll('template[data-phem-keep]') as $placeholder) {
                    $kid = (string) $placeholder->getAttribute('data-phem-keep');
                    $old = $kept[$kid] ?? null;
                    if (null === $old) {
                        Assert::fail(\sprintf('The event keeps the component "%s", but the page has no element with this fiber id.', $kid));
                    }

                    $placeholder->replaceWith($old);
                }
            }

            $this->moveFiles($inputs, $nodes);
        }
    }

    /** The element with a fiber id: the root element of the component, or its `<phem-c>`. */
    private function component(string $id): ?Element
    {
        return $this->document->querySelector('[data-phem-c="'.$id.'"]');
    }

    /**
     * The handler id and the DOM event of an element, as the `phem` Stimulus controller makes
     * them, and the file inputs whose files it sends, or null when no handler gets the event.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: list<Element>}|null
     */
    private function event(Element $target, string $type, ?Element $submitter = null): ?array
    {
        // React's onChange gets each key of a text field, also the onChange of its form. A
        // `phem:live` field sends its own events, and the form gets its native change.
        $textChange = static fn (Element $e): bool => self::isTextLike($e) || ('form' === $e->localName && self::isTextLike($target) && !$target->hasAttribute('data-phem-live'));
        $element = match ($type) {
            'input' => $this->handlerElement($target, 'input') ?? (null !== ($e = $this->handlerElement($target, 'change')) && $textChange($e) ? $e : null),
            'change' => null !== ($e = $this->handlerElement($target, 'change')) && !$textChange($e) ? $e : null,
            default => $this->handlerElement($target, $type),
        };
        if (null === $element) {
            return null;
        }

        $handler = (string) $element->getAttribute('data-phem-on-'.('input' === $type && !$element->hasAttribute('data-phem-on-input') ? 'change' : $type));

        // A submit, a change or a click of an element in a form sends the fields of the form. The
        // change and the click send the touched fields too, and only the submit sends the files.
        $form = \in_array($type, self::FORM_EVENTS, true) ? self::ownerForm($element) : null;
        $event = [
            'type' => $type,
            'value' => self::valueOf($element),
            'checked' => 'input' === $element->localName ? $element->hasAttribute('checked') : null,
            'key' => null,
            'form' => null !== $form ? self::fields($form, $submitter) : null,
        ];

        if (null !== $form && 'submit' !== $type) {
            $event['touched'] = self::touchedNames($form);
        }

        $inputs = null !== $form && 'submit' === $type ? $this->fileInputs($form) : [];
        if ([] !== $inputs) {
            $event['files'] = $this->files($inputs);
        }

        return [$handler, $event, $inputs];
    }

    /** The closest element with a handler for the event, in the root element of the page. */
    private function handlerElement(Element $target, string $type): ?Element
    {
        $element = $target->closest('[data-phem-on-'.$type.']');
        $root = $this->document->querySelector('[data-phem-state-value]') ?? $this->document->body;

        return null !== $element && null !== $root && $root->contains($element) ? $element : null;
    }

    /**
     * The fiber id of the closest component with state around an element (its `data-phem-c`),
     * or null for the root component. A component with no #[State] property
     * (a `<Root>`, a function component) is not a match.
     */
    private function fiberOf(string $selector): ?string
    {
        $element = $this->document->querySelector($selector) ?? Assert::fail(\sprintf('No element matches "%s". %s', $selector, $this->context()));

        for ($c = $element->closest('[data-phem-c]'); null !== $c; $c = $c->parentElement?->closest('[data-phem-c]')) {
            $id = (string) $c->getAttribute('data-phem-c');
            if ([] !== $this->driver->state($id)) {
                return $id;
            }
        }

        return null;
    }

    private function enabled(string $selector): Element
    {
        $element = $this->document->querySelector($selector) ?? Assert::fail(\sprintf('No element matches "%s". %s', $selector, $this->context()));
        if ($element->hasAttribute('disabled') || null !== $element->closest('fieldset[disabled]')) {
            Assert::fail(\sprintf('The element "%s" is disabled: a browser sends no event.', $selector));
        }

        return $element;
    }

    /**
     * @param string|int|float|bool|list<string> $value
     *
     * @return Element the element that changed (a radio button of the group)
     */
    private function setValue(Element $element, string|int|float|bool|array $value, string $selector): Element
    {
        $this->edit($element);
        $type = strtolower($element->getAttribute('type') ?? 'text');

        // As in the browser, a field of a form that the user changes is touched.
        if (null !== $element->getAttribute('name') && null !== self::ownerForm($element)) {
            $element->setAttribute(TouchedFields::MARK, '');
        }

        switch (true) {
            case 'input' === $element->localName && 'radio' === $type:
                if (!\is_bool($value)) {
                    $element = $this->radio($element, self::scalar($value, $selector), $selector);
                    $value = true;
                }

                if ($value) {
                    foreach ($this->radioGroup($element) as $radio) {
                        $radio->removeAttribute('checked');
                    }
                }

                $element->toggleAttribute('checked', $value);
                break;
            case 'input' === $element->localName && 'checkbox' === $type:
                if (!\is_bool($value)) {
                    Assert::fail(\sprintf('The checkbox "%s" takes true or false.', $selector));
                }

                $element->toggleAttribute('checked', $value);
                break;
            case 'select' === $element->localName:
                $values = array_map(strval(...), (array) $value);
                $found = [];

                foreach ($element->querySelectorAll('option') as $option) {
                    $selected = \in_array(self::optionValue($option), $values, true);
                    $option->toggleAttribute('selected', $selected);
                    if ($selected) {
                        $found[] = self::optionValue($option);
                    }
                }

                if ([] !== $missing = array_diff($values, $found)) {
                    Assert::fail(\sprintf('The select "%s" has no option "%s".', $selector, implode('", "', $missing)));
                }

                break;
            case 'textarea' === $element->localName:
                $element->textContent = self::scalar($value, $selector);
                break;
            case 'input' === $element->localName:
                $element->setAttribute('value', self::scalar($value, $selector));
                break;
            default:
                Assert::fail(\sprintf('The element "%s" is not a form field.', $selector));
        }

        return $element;
    }

    /**
     * Keeps the server value of the fields of a bound key before the first change, so the
     * morph can give it back (see send()).
     */
    private function edit(Element $field): void
    {
        $key = $field->getAttribute('data-phem-bind');
        if (null === $key || isset($this->edited[$key])) {
            return;
        }

        $fields = array_filter(iterator_to_array($this->document->querySelectorAll('[data-phem-bind]'), false), static fn (Element $f): bool => $f->getAttribute('data-phem-bind') === $key);
        $this->edited[$key] = array_values(array_map(static fn (Element $f): array => [$f, self::snapshot($f)], $fields));
    }

    /** @return \Closure(): void gives back the value that the field has now */
    private static function snapshot(Element $field): \Closure
    {
        $value = $field->getAttribute('value');
        $checked = $field->hasAttribute('checked');
        $text = $field->textContent;
        $selected = 'select' === $field->localName ? array_map(static fn (Element $o): bool => $o->hasAttribute('selected'), iterator_to_array($field->querySelectorAll('option'), false)) : [];

        return static function () use ($field, $value, $checked, $text, $selected): void {
            switch ($field->localName) {
                case 'select':
                    foreach (iterator_to_array($field->querySelectorAll('option'), false) as $i => $option) {
                        $option->toggleAttribute('selected', $selected[$i] ?? false);
                    }

                    break;
                case 'textarea':
                    $field->textContent = $text;
                    break;
                default:
                    null === $value ? $field->removeAttribute('value') : $field->setAttribute('value', $value);
                    $field->toggleAttribute('checked', $checked);
            }
        };
    }

    /**
     * The value of a bound key, from its fields, as the browser sends it.
     *
     * @param list<Element> $fields
     *
     * @return string|bool|list<string>|null
     */
    private static function boundValue(array $fields): string|bool|array|null
    {
        $first = $fields[0];
        $type = strtolower($first->getAttribute('type') ?? 'text');

        return match (true) {
            'input' === $first->localName && 'checkbox' === $type && $first->hasAttribute('data-phem-bind-list') => array_values(array_map(
                static fn (Element $f): string => $f->getAttribute('value') ?? 'on',
                array_filter($fields, static fn (Element $f): bool => $f->hasAttribute('checked')),
            )),
            'input' === $first->localName && 'checkbox' === $type => $first->hasAttribute('checked'),
            'input' === $first->localName && 'radio' === $type => array_values(array_map(
                static fn (Element $f): string => $f->getAttribute('value') ?? 'on',
                array_filter($fields, static fn (Element $f): bool => $f->hasAttribute('checked')),
            ))[0] ?? null,
            'select' === $first->localName && $first->hasAttribute('multiple') => array_map(self::optionValue(...), self::selectedOptions($first)),
            default => self::valueOf($first),
        };
    }

    /** @param string|int|float|bool|list<string> $value */
    private function fillField(Element $form, string $name, string|int|float|bool|array $value): void
    {
        $fields = array_values(array_filter(
            iterator_to_array($form->querySelectorAll('[name="'.addcslashes($name, '"\\').'"]'), false),
            static fn (Element $e): bool => \in_array($e->localName, ['input', 'select', 'textarea'], true),
        ));
        if ([] === $fields) {
            Assert::fail(\sprintf('The form has no field "%s".', $name));
        }

        $type = strtolower($fields[0]->getAttribute('type') ?? '');
        if ('checkbox' === $type && \count($fields) > 1) {
            $this->edit($fields[0]);

            // A group of checkboxes (`tags[]`): the values to check.
            $values = array_map(strval(...), (array) $value);
            foreach ($fields as $checkbox) {
                $checkbox->toggleAttribute('checked', \in_array($checkbox->getAttribute('value') ?? 'on', $values, true));
            }

            return;
        }

        $this->setValue($fields[0], $value, \sprintf('[name="%s"]', $name));
    }

    private function radio(Element $radio, string $value, string $selector): Element
    {
        foreach ([$radio, ...$this->radioGroup($radio)] as $button) {
            if (($button->getAttribute('value') ?? 'on') === $value) {
                return $button;
            }
        }

        Assert::fail(\sprintf('The radio group "%s" has no button with the value "%s".', $selector, $value));
    }

    /** @return list<Element> */
    private function radioGroup(Element $radio): array
    {
        $name = $radio->getAttribute('name');
        if (null === $name) {
            return [];
        }

        $scope = $radio->closest('form') ?? $this->document;

        return iterator_to_array($scope->querySelectorAll('input[type="radio"][name="'.addcslashes($name, '"\\').'"]'), false);
    }

    /** The form that a submit button submits. */
    private static function formOf(Element $element): ?Element
    {
        $submits = match ($element->localName) {
            'button' => 'submit' === strtolower($element->getAttribute('type') ?? 'submit'),
            'input' => \in_array(strtolower($element->getAttribute('type') ?? ''), ['submit', 'image'], true),
            default => false,
        };

        return $submits ? self::ownerForm($element) : null;
    }

    /**
     * The form of an element, as the `phem` Stimulus controller finds it: the form itself, the form
     * of its `form` attribute, or the form around it.
     */
    private static function ownerForm(Element $element): ?Element
    {
        if ('form' === $element->localName) {
            return $element;
        }

        $id = $element->getAttribute('form');

        return (null !== $id ? $element->ownerDocument?->getElementById($id) : null) ?? $element->closest('form');
    }

    /**
     * The controls of a form that match a selector, in the order of the page, as `form.elements`
     * has them: also the controls outside the form with its id in their `form` attribute.
     *
     * @return list<Element>
     */
    private static function controls(Element $form, string $selector): array
    {
        $document = $form->ownerDocument ?? throw new \LogicException('The form has no document.');

        return array_values(array_filter(
            iterator_to_array($document->querySelectorAll($selector), false),
            static fn (Element $control): bool => self::ownerForm($control) === $form,
        ));
    }

    /**
     * The fields of a form as [name, value] pairs, as `new FormData(form, submitter)` makes them,
     * without the files.
     *
     * @return list<array{string, string}>
     */
    private static function fields(Element $form, ?Element $submitter): array
    {
        $fields = [];
        foreach (self::controls($form, 'input, select, textarea, button') as $field) {
            $name = $field->getAttribute('name');
            if (null === $name || '' === $name || $field->hasAttribute('disabled') || null !== $field->closest('fieldset[disabled]')) {
                continue;
            }

            $type = strtolower($field->getAttribute('type') ?? ('button' === $field->localName ? 'submit' : 'text'));
            switch (true) {
                case 'button' === $field->localName || \in_array($type, ['submit', 'image', 'reset', 'button'], true):
                    if ($field === $submitter) {
                        $fields[] = [$name, $field->getAttribute('value') ?? ''];
                    }

                    break;
                case 'file' === $type:
                    break;
                case 'checkbox' === $type || 'radio' === $type:
                    if ($field->hasAttribute('checked')) {
                        $fields[] = [$name, $field->getAttribute('value') ?? 'on'];
                    }

                    break;
                case 'select' === $field->localName:
                    foreach (self::selectedOptions($field) as $option) {
                        $fields[] = [$name, self::optionValue($option)];
                    }

                    break;
                default:
                    $fields[] = [$name, (string) self::valueOf($field)];
            }
        }

        return $fields;
    }

    /**
     * The names of the touched fields of a form (`data-phem-touched`), as the `phem` Stimulus
     * controller sends them: the server marks them, and a change marks its field.
     *
     * @return list<string>
     */
    private static function touchedNames(Element $form): array
    {
        $names = [];
        foreach (self::controls($form, '['.TouchedFields::MARK.'][name]') as $field) {
            $name = (string) $field->getAttribute('name');
            $names[$name] = $name;
        }

        return array_values($names);
    }

    /**
     * The files of the file inputs of a form as [name, path] pairs, as `new FormData(form)` has
     * them (see fileInputs()).
     *
     * @param list<Element> $inputs
     *
     * @return list<array{string, string}>
     */
    private function files(array $inputs): array
    {
        $files = [];
        foreach ($inputs as $input) {
            foreach ($this->selected[$input] as $path) {
                $files[] = [(string) $input->getAttribute('name'), $path];
            }
        }

        return $files;
    }

    /**
     * The file inputs of a form whose files a submit sends: each file input with a name, not
     * disabled, and with files.
     *
     * @return list<Element>
     */
    private function fileInputs(Element $form): array
    {
        $inputs = [];
        foreach (self::controls($form, 'input[type=file i]') as $input) {
            $name = $input->getAttribute('name');
            if (null !== $name && '' !== $name && !$input->hasAttribute('disabled') && null === $input->closest('fieldset[disabled]') && [] !== ($this->selected[$input] ?? [])) {
                $inputs[] = $input;
            }
        }

        return $inputs;
    }

    /**
     * The file inputs of an element: the element itself, and the file inputs in it.
     *
     * @return list<Element>
     */
    private static function fileInputsIn(Element $element): array
    {
        return [...($element->matches('input[type=file i]') ? [$element] : []), ...$element->querySelectorAll('input[type=file i]')];
    }

    /**
     * After a morph: moves the files of the replaced file inputs to the new inputs that the
     * morph matches with them, as in a browser, where idiomorph keeps the element of the input
     * and its files. An input with an id matches the new input with the same id. An input with no
     * id matches the new input at its position among the inputs with its name. The other new
     * inputs have no files. With no new nodes (a push that is not a morph), the files of the
     * replaced inputs go away.
     *
     * @param list<array{0: Element, 1: int}> $old   the file inputs before the morph, with their position (see positions())
     * @param list<\Dom\Node>                $nodes the new HTML, in the page
     */
    private function moveFiles(array $old, array $nodes): void
    {
        if (0 === $this->selected->count()) {
            return;
        }

        $new = [];
        foreach ($nodes as $node) {
            if ($node instanceof Element) {
                array_push($new, ...self::fileInputsIn($node));
            }
        }

        $new = self::positions($new);
        $olds = array_map(static fn (array $input): Element => $input[0], $old);

        foreach ($old as [$input, $position]) {
            // A kept component, or a `data-phem-ignore` element: the morph keeps the element.
            if ($input->isConnected || !isset($this->selected[$input])) {
                continue;
            }

            $paths = $this->selected[$input];
            unset($this->selected[$input]);

            $id = (string) $input->getAttribute('id');
            foreach ($new as [$next, $at]) {
                $match = '' !== $id ? $id === $next->getAttribute('id') : $at === $position && $next->getAttribute('name') === $input->getAttribute('name');
                if ($match && !\in_array($next, $olds, true)) {
                    $this->selected[$next] = $paths;
                    break;
                }
            }
        }
    }

    /**
     * Each file input with its position among the inputs with the same name.
     *
     * @param list<Element> $inputs
     *
     * @return list<array{0: Element, 1: int}>
     */
    private static function positions(array $inputs): array
    {
        $counts = [];
        $positions = [];
        foreach ($inputs as $input) {
            $name = (string) $input->getAttribute('name');
            $counts[$name] = ($counts[$name] ?? -1) + 1;
            $positions[] = [$input, $counts[$name]];
        }

        return $positions;
    }

    /** The value of an element, as `el.value` in the browser; null for an element without a value. */
    private static function valueOf(Element $element): ?string
    {
        return match ($element->localName) {
            'input' => $element->getAttribute('value') ?? (\in_array(strtolower($element->getAttribute('type') ?? ''), ['checkbox', 'radio'], true) ? 'on' : ''),
            'textarea' => $element->textContent ?? '',
            'select' => null !== ($option = self::selectedOptions($element)[0] ?? null) ? self::optionValue($option) : '',
            'button', 'option' => 'option' === $element->localName ? self::optionValue($element) : ($element->getAttribute('value') ?? ''),
            default => null,
        };
    }

    /** @return list<Element> the selected options; the first option of a single select without one */
    private static function selectedOptions(Element $select): array
    {
        $options = iterator_to_array($select->querySelectorAll('option'), false);
        $selected = array_values(array_filter($options, static fn (Element $o): bool => $o->hasAttribute('selected') && !$o->hasAttribute('disabled')));
        if ([] !== $selected || $select->hasAttribute('multiple')) {
            return $selected;
        }

        foreach ($options as $option) {
            if (!$option->hasAttribute('disabled')) {
                return [$option];
            }
        }

        return [];
    }

    private static function optionValue(Element $option): string
    {
        return $option->getAttribute('value') ?? self::normalize($option->textContent ?? '');
    }

    private static function isTextLike(Element $element): bool
    {
        return 'textarea' === $element->localName
            || ('input' === $element->localName && \in_array(strtolower($element->getAttribute('type') ?? 'text'), self::TEXT_INPUTS, true));
    }

    /** @param string|int|float|bool|list<string> $value */
    private static function scalar(string|int|float|bool|array $value, string $selector): string
    {
        if (\is_array($value) || \is_bool($value)) {
            Assert::fail(\sprintf('The field "%s" takes a string.', $selector));
        }

        return (string) $value;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The status of the last response, for the message of a failed assertion. */
    private function dispatchedNames(): string
    {
        return [] === $this->dispatched ? '' : \sprintf(' (it dispatched "%s")', implode('", "', array_unique(array_column($this->dispatched, 'name'))));
    }

    private function context(): string
    {
        return match (true) {
            $this->reload => 'The last event reloads the page (409).',
            null !== $this->redirect => \sprintf('The last response redirects to "%s".', $this->redirect),
            200 !== $this->status => \sprintf('The status of the last response is %d.', $this->status),
            default => '',
        };
    }
}
