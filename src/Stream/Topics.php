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

namespace Phem\Stream;

use Phem\Attribute\Broadcast;
use Phem\Metadata\ComponentMetadata;
use Phem\Routing\PageRouteValues;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Mercure topics of the pages and the components with #[Broadcast] (a class or a function):
 *
 * - `phem:<page>`: all the URLs of the page;
 * - `phem:<page>?<route values>`: the URL of the page with these route values
 *   (`phem:App\Ui\Scientist\ScientistPage?id=42`), for a page with route values;
 * - `phem:*`: all the pages with #[Broadcast] (see Streams::everywhere());
 * - `phem:<component>`: all the pages that render the component (`phem:App\Ui\Mail\Inbox`).
 *
 * The browser, the access token and the hub see only an HMAC of the name of a page topic, with
 * the secret of Phem (`phem:` and 22 base64url characters): not the class names of the
 * application, and not the route values. name() gives the name back, for the profiler.
 *
 * The values of a URL are the #[Path] props of the page (see PathTopicValues): an entity prop is
 * the identifier of its entity, so all the routes of the page give the same topic for the same
 * entity (`{order}`, `{id:order}` and `{slug:order}` give `order[id]=42`). A scalar prop is its
 * route value.
 *
 * A page with #[Broadcast(on: ...)] and more than one #[Path] prop also subscribes to a topic for
 * each entity prop alone (`phem:<page>?order[id]=42`): a change of one entity selects these URLs
 * (see EntityRefreshes). With one prop, this is the topic of the URL.
 */
final class Topics implements ResetInterface
{
    public const EVERYWHERE = 'phem:*';

    private const PREFIX = 'phem:';

    /** @var array<string, string> topic => its name, for the topics of this request */
    private array $names = [];
    private readonly PathTopicValues $values;

    /**
     * @param string               $secret the key of the HMAC of the topics (`phem.secret`)
     * @param PageRouteValues|null $routes the route values of the pages: without them, all the
     *                                     parameters of a function page are route values
     * @param PathTopicValues|null $values the values of a URL from the #[Path] props (with no
     *                                     entities by default)
     */
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly string $secret = '',
        private readonly ?PageRouteValues $routes = null,
        ?PathTopicValues $values = null,
    ) {
        $this->values = $values ?? new PathTopicValues($metadata);
    }

    /** The name of a topic of this request (`phem:App\Ui\GuestbookPage`), or the topic. */
    public function name(string $topic): string
    {
        return $this->names[$topic] ?? $topic;
    }

    public function reset(): void
    {
        $this->names = [];
    }

    /**
     * The topics of a page render: the topics of the page when it has #[Broadcast], and the
     * topic of each component of the page with #[Broadcast]. None when nothing has #[Broadcast].
     *
     * @param array<string, mixed> $values     the #[Path] route values of the request
     * @param list<string>         $components the components of the page with #[Broadcast] (see components())
     * @param array<string, mixed> $props      the resolved props of the page: an entity is its identifier
     *
     * @return list<string>
     */
    public function ofPage(string $page, array $values, array $components = [], array $props = []): array
    {
        $topics = [];
        if ($this->broadcasts($page)) {
            $topics[] = $this->topic(self::PREFIX.$page);
            if ([] !== $values) {
                $url = $this->values->ofRender($values, $props);
                $topics[] = $this->scoped($page, $url);

                // One entity selects the URLs of a page with more #[Path] props (see EntityRefreshes).
                if (\count($url) > 1 && [] !== $this->metadata->entityBroadcasts($page)) {
                    foreach ($this->values->entities($props) as $name) {
                        if (\array_key_exists($name, $url)) {
                            $topics[] = $this->scoped($page, [$name => $url[$name]]);
                        }
                    }
                }
            }

            $topics[] = self::EVERYWHERE;
        }

        foreach ($components as $component) {
            $topics[] = $this->topic(self::PREFIX.$component);
        }

        return $topics;
    }

    /**
     * The components with #[Broadcast] in a list of components (the fibers of a page render),
     * and the components with #[Broadcast] that they replace, once each, with no page.
     *
     * @param iterable<string> $names
     *
     * @return list<string>
     */
    public function components(iterable $names): array
    {
        $components = [];
        foreach ($names as $name) {
            // A replacement gets the streams to the component that it replaces too.
            foreach ([$name, $this->metadata->replaces($name)] as $component) {
                if (null !== $component && $this->broadcasts($component) && $this->metadata->isComponent($component)) {
                    $components[$component] = $component;
                }
            }
        }

        return array_values($components);
    }

    /**
     * The topic of Streams::toPage(): the page, or the page with all its route values.
     *
     * @param array<string, mixed> $values
     *
     * @throws \LogicException when the page has no #[Broadcast], when it is a component, or when the values are not the route values of the page
     */
    public function toPage(string $page, array $values = []): string
    {
        $page = ltrim($page, '\\');
        if (!$this->broadcasts($page)) {
            throw new \LogicException(\sprintf('The streams go to the pages with #[Broadcast], but "%s" has no #[%s] attribute.', $page, Broadcast::class));
        }

        if ($this->metadata->isComponent($page)) {
            throw new \LogicException(\sprintf('"%s" is a component, not a page: use Streams::toComponent().', $page));
        }

        if ([] === $values) {
            return $this->topic(self::PREFIX.$page);
        }

        $paths = $this->paths($page);
        $what = class_exists($page) ? '#[Path] prop' : 'route value parameter';
        if ([] !== $unknown = array_diff_key($values, $paths)) {
            throw new \LogicException(\sprintf('The page "%s" has no %s "%s": give the route values of its %ss (%s).', $page, $what, implode('", "', array_keys($unknown)), $what, [] !== $paths ? '"'.implode('", "', array_keys($paths)).'"' : 'it has none'));
        }

        if ([] !== $missing = array_diff_key($paths, $values)) {
            throw new \LogicException(\sprintf('The streams to one URL of the page "%s" need all its route values, but "%s" is missing. Give no values for all the URLs of the page.', $page, implode('", "', array_keys($missing))));
        }

        return $this->scoped($page, $this->values->ofPublish($page, $values));
    }

    /**
     * The topic of the URLs of a page with an entity in one #[Path] prop: the URL of a page with
     * one #[Path] prop, else the topic of this prop alone (see ofPage()).
     *
     * @internal see EntityRefreshes
     */
    public function toPageProp(string $page, string $prop, object $entity): string
    {
        $page = ltrim($page, '\\');

        return $this->scoped($page, $this->values->ofPublish($page, [$prop => $entity]));
    }

    /**
     * The topic of Streams::toComponent(): all the pages that render the component.
     *
     * @throws \LogicException when the component has no #[Broadcast], or when it is not a component
     */
    public function toComponent(string $component): string
    {
        $component = ltrim($component, '\\');
        if (!$this->broadcasts($component)) {
            throw new \LogicException(\sprintf('The streams go to the components with #[Broadcast], but "%s" has no #[%s] attribute on it or on a handler.', $component, Broadcast::class));
        }

        if (!$this->metadata->isComponent($component)) {
            throw new \LogicException(\sprintf('"%s" is not a component (a #[Component] with no #[Page]): use Streams::toPage() for a page.', $component));
        }

        return $this->topic(self::PREFIX.$component);
    }

    /** Whether a page or a component has #[Broadcast] (see ComponentMetadata::broadcasts()). */
    public function broadcasts(string $name): bool
    {
        return $this->metadata->broadcasts($name);
    }

    /**
     * The props of a page that get a route value: the #[Path] props of a class, the parameters
     * of a function that its routes give a value.
     *
     * @return array<string, ?\ReflectionType>
     *
     * @internal see EntityRefreshes
     */
    public function paths(string $page): array
    {
        $paths = $this->metadata->pathProps($page);
        if (null === $this->routes) {
            return $paths;
        }

        return array_intersect_key($paths, array_flip($this->routes->of($page)));
    }

    /** @param array<string, mixed> $values */
    private function scoped(string $page, array $values): string
    {
        return $this->topic(self::PREFIX.$page.'?'.http_build_query($this->normalize($values), '', '&', \PHP_QUERY_RFC3986));
    }

    /** The topic of a name: an HMAC of 128 bits (see the class). */
    private function topic(string $name): string
    {
        $topic = self::PREFIX.NameHash::of($this->secret, $name);
        $this->names[$topic] = $name;

        return $topic;
    }

    /**
     * The route values as strings, with their keys in order (see NameValues).
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function normalize(array $values): array
    {
        return $this->values->normalize($values, static fn (string $key, mixed $value): \Throwable => new \LogicException(\sprintf('The route value "%s" of a topic is a "%s": give an entity, or the value of the URL.', $key, get_debug_type($value))));
    }
}
