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

use Phem\Attribute\Subscribe;
use Phem\Metadata\ComponentMetadata;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Mercure topics of the pages with #[Subscribe]:
 *
 * - `phem:<page class>`: all the URLs of the page;
 * - `phem:<page class>?<route values>`: the URL of the page with these #[Path] route values
 *   (`phem:App\Pages\ScientistPage?id=42`), for a page with #[Path] props;
 * - `phem:*`: all the pages with #[Subscribe] (see Streams::everywhere()).
 *
 * The browser, the access token and the hub see only an HMAC of the name of a page topic, with
 * the secret of Phem (`phem:` and 22 base64url characters): not the class names of the
 * application, and not the route values. name() gives the name back, for the profiler.
 *
 * The route values are the values of the URL, as the page gets them (see Phem\Http\RouteValues):
 * scalars, or the criteria of an entity prop (`{slug:post}` gives `['post' => ['slug' => …]]`).
 */
final class Topics implements ResetInterface
{
    public const EVERYWHERE = 'phem:*';

    private const PREFIX = 'phem:';

    /** @var array<string, bool> */
    private array $subscribes = [];
    /** @var array<string, string> topic => its name, for the topics of this request */
    private array $names = [];

    /**
     * @param string $secret the key of the HMAC of the topics (`phem.secret`)
     */
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly string $secret = '',
    ) {
    }

    /** The name of a topic of this request (`phem:App\Pages\Guestbook`), or the topic. */
    public function name(string $topic): string
    {
        return $this->names[$topic] ?? $topic;
    }

    public function reset(): void
    {
        $this->names = [];
    }

    /**
     * The topics of a page render, or none when the page has no #[Subscribe].
     *
     * @param array<string, mixed> $values the #[Path] route values of the request
     *
     * @return list<string>
     */
    public function ofPage(string $page, array $values): array
    {
        if (!$this->subscribes($page)) {
            return [];
        }

        $topics = [$this->topic(self::PREFIX.$page)];
        if ([] !== $values) {
            $topics[] = $this->scoped($page, $values);
        }

        $topics[] = self::EVERYWHERE;

        return $topics;
    }

    /**
     * The topic of Streams::to(): the page, or the page with all its #[Path] route values.
     *
     * @param array<string, mixed> $values
     *
     * @throws \LogicException when the class is not a page with #[Subscribe], or when the values are not the #[Path] route values of the page
     */
    public function to(string $page, array $values = []): string
    {
        $page = ltrim($page, '\\');
        if (!$this->subscribes($page)) {
            throw new \LogicException(\sprintf('The streams go to the pages with #[Subscribe], but "%s" has no #[%s] attribute.', $page, Subscribe::class));
        }

        if ([] === $values) {
            return $this->topic(self::PREFIX.$page);
        }

        $paths = $this->metadata->pathProps($page);
        if ([] !== $unknown = array_diff_key($values, $paths)) {
            throw new \LogicException(\sprintf('The page "%s" has no #[Path] prop "%s": give the route values of its #[Path] props (%s).', $page, implode('", "', array_keys($unknown)), [] !== $paths ? '"'.implode('", "', array_keys($paths)).'"' : 'it has none'));
        }

        if ([] !== $missing = array_diff_key($paths, $values)) {
            throw new \LogicException(\sprintf('The streams to one URL of the page "%s" need all its #[Path] route values, but "%s" is missing. Give no values for all the URLs of the page.', $page, implode('", "', array_keys($missing))));
        }

        return $this->scoped($page, $values);
    }

    public function subscribes(string $page): bool
    {
        return $this->subscribes[$page] ??= class_exists($page) && [] !== new \ReflectionClass($page)->getAttributes(Subscribe::class);
    }

    /** @param array<string, mixed> $values */
    private function scoped(string $page, array $values): string
    {
        return $this->topic(self::PREFIX.$page.'?'.http_build_query(self::normalize($values), '', '&', \PHP_QUERY_RFC3986));
    }

    /** The topic of a name: an HMAC of 128 bits (see the class). */
    private function topic(string $name): string
    {
        $topic = self::PREFIX.rtrim(strtr(base64_encode(substr(hash_hmac('sha256', $name, $this->secret, true), 0, 16)), '+/', '-_'), '=');
        $this->names[$topic] = $name;

        return $topic;
    }

    /**
     * The route values as strings, with their keys in order: the page and the publisher get the
     * same topic for `42` and `"42"`.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function normalize(array $values): array
    {
        ksort($values);
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                \is_array($value) => self::normalize($value),
                $value instanceof \BackedEnum => (string) $value->value,
                \is_scalar($value), $value instanceof \Stringable => (string) $value,
                null === $value => '',
                default => throw new \LogicException(\sprintf('The route value "%s" of a topic is a "%s": give the value of the URL (an identifier or a slug), not the object.', $key, get_debug_type($value))),
            };
        }

        return $values;
    }
}
