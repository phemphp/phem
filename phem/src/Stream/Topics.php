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

/**
 * The Mercure topics of the pages with #[Subscribe]:
 *
 * - `phem:<page class>`: all the URLs of the page;
 * - `phem:<page class>?<route values>`: the URL of the page with these #[Path] route values
 *   (`phem:App\Pages\ScientistPage?id=42`), for a page with #[Path] props;
 * - `phem:*`: all the pages with #[Subscribe] (see Streams::everywhere()).
 *
 * The route values are the values of the URL, as the page gets them (see Phem\Http\RouteValues):
 * scalars, or the criteria of an entity prop (`{slug:post}` gives `['post' => ['slug' => …]]`).
 */
final class Topics
{
    public const EVERYWHERE = 'phem:*';

    private const PREFIX = 'phem:';

    /** @var array<string, bool> */
    private array $subscribes = [];

    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
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
        $topics = [self::PREFIX.$page];
        if ([] !== $values) {
            $topics[] = self::scoped($page, $values);
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
            return self::PREFIX.$page;
        }
        $paths = $this->metadata->pathProps($page);
        if ([] !== $unknown = array_diff_key($values, $paths)) {
            throw new \LogicException(\sprintf('The page "%s" has no #[Path] prop "%s": give the route values of its #[Path] props (%s).', $page, implode('", "', array_keys($unknown)), [] !== $paths ? '"'.implode('", "', array_keys($paths)).'"' : 'it has none'));
        }
        if ([] !== $missing = array_diff_key($paths, $values)) {
            throw new \LogicException(\sprintf('The streams to one URL of the page "%s" need all its #[Path] route values, but "%s" is missing. Give no values for all the URLs of the page.', $page, implode('", "', array_keys($missing))));
        }

        return self::scoped($page, $values);
    }

    public function subscribes(string $page): bool
    {
        return $this->subscribes[$page] ??= class_exists($page) && [] !== new \ReflectionClass($page)->getAttributes(Subscribe::class);
    }

    /** @param array<string, mixed> $values */
    private static function scoped(string $page, array $values): string
    {
        return self::PREFIX.$page.'?'.http_build_query(self::normalize($values), '', '&', \PHP_QUERY_RFC3986);
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
