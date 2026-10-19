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

namespace Phem\Tests\Runtime;

use Phem\Attribute\State;
use Phem\Attribute\Url;
use Phem\Metadata\ComponentMetadata;
use Phem\State\UrlQuery;
use Phem\Tests\Fixtures\Size;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `#[Url]`: the first render reads the query string, and each render gives the query of the URL.
 */
final class UrlTest extends TestCase
{
    use FixtureApp;

    public bool $bool = false;
    public ?bool $nullableBool = null;
    public int $int = 0;

    public function testTheFirstRenderReadsTheQueryString(): void
    {
        $html = (string) $this->request('GET', '/url?q=php&page=3&stock=1&tags[]=a&tags[]=b&size=l&min=1.5&sort=date&tab=new')->getContent();

        self::assertStringContainsString('q=php, page=3, stock=yes, tags=a+b, size=l, min=1.5, sort=date', $html);
        self::assertStringContainsString('Tab new', $html, 'a #[Url] property of the page');
        self::assertSame(['values' => ['tab' => 'new', 'q' => 'php', 'page' => '3', 'stock' => '1', 'tags' => ['a', 'b'], 'size' => 'l', 'min' => '1.5', 'sort' => 'date', 'filters' => null], 'push' => ['page']], self::query($html));
    }

    public function testAValueThatDoesNotFitItsTypeIsIgnored(): void
    {
        $html = (string) $this->request('GET', '/url?q[]=php&page=abc&stock=maybe&tags=a&size=xl&min=x')->getContent();

        self::assertStringContainsString('q=, page=1, stock=no, tags=, size=none, min=0, sort=name', $html);
        self::assertSame(['tab' => null, 'q' => null, 'page' => null, 'stock' => null, 'tags' => null, 'size' => null, 'min' => null, 'sort' => null, 'filters' => null], self::query($html)['values'], 'the browser removes the keys');
    }

    public function testAnEventAnswersWithTheQuery(): void
    {
        [$html, $state, $signature] = $this->open('/url?q=php');

        $data = $this->send('/url?q=php', $state, $signature, self::handler($html, 'next'));

        self::assertStringContainsString('q=php, page=2', $data['streams']);
        self::assertSame(['q' => 'php', 'page' => '2', 'stock' => null, 'tags' => null, 'size' => null, 'min' => null, 'sort' => null, 'filters' => null], $data['query']['values'], 'no "tab": the page did not render again, so the browser keeps its keys');
        self::assertSame(['page'], $data['query']['push']);
    }

    public function testAValueEqualToTheDefaultValueRemovesItsKey(): void
    {
        [$html, $state, $signature] = $this->open('/url?q=php&page=4&stock=true&tags[]=a&size=s&min=2&filters[status]=new&filters[price][max]=5');

        $data = $this->send('/url', $state, $signature, self::handler($html, 'clear'));

        self::assertSame([null], array_unique(array_values($data['query']['values'])));
    }

    public function testTheFirstRenderReadsANestedValue(): void
    {
        $html = (string) $this->request('GET', '/url?filters[status]=new&filters[open]=1&filters[price][max]=50&filters[tags][]=a&filters[tags][]=b')->getContent();

        self::assertStringContainsString('status=new, open=yes, price=0-50, tags=a+b', $html);
        self::assertSame(['status' => 'new', 'open' => '1', 'price' => ['max' => '50'], 'tags' => ['a', 'b']], self::query($html)['values']['filters']);
    }

    public function testANestedValueSetsOnlyTheKeysOfTheArray(): void
    {
        $html = (string) $this->request('GET', '/url?filters[status]=new&filters[admin]=1&filters[open]=maybe&filters[price][max]=x&filters[price][min][]=1&filters[tags]=a')->getContent();

        self::assertStringContainsString('status=new, open=no, price=0-100, tags=', $html, 'a key that does not fit keeps its value');
        self::assertStringNotContainsString('admin', $html);
        self::assertSame(['status' => 'new'], self::query($html)['values']['filters'], 'a new key is ignored');
    }

    public function testANestedValueThatIsNotAnArrayIsIgnored(): void
    {
        $html = (string) $this->request('GET', '/url?filters=open')->getContent();

        self::assertStringContainsString('status=all, open=no, price=0-100, tags=', $html);
    }

    public function testAnEventAnswersWithTheChangedKeysOfANestedValue(): void
    {
        [$html, $state, $signature] = $this->open('/url');

        $data = $this->send('/url', $state, $signature, self::handler($html, 'filter'));

        self::assertStringContainsString('status=new, open=no, price=0-50, tags=a+b', $data['streams']);
        self::assertSame(['status' => 'new', 'price' => ['max' => '50'], 'tags' => ['a', 'b']], $data['query']['values']['filters']);
        self::assertSame('/url?filters[status]=new&filters[price][max]=50&filters[tags][]=a&filters[tags][]=b', UrlQuery::merge('/url', $data['query']['values']));
    }

    public function testTheExceptValueRemovesItsKey(): void
    {
        [$html, $state, $signature] = $this->open('/url');

        $data = $this->send('/url', $state, $signature, self::handler($html, 'sort'));
        self::assertStringContainsString('sort=date', $data['streams']);
        self::assertSame('date', $data['query']['values']['sort'], 'a property with no default value');

        $data = $this->send('/url?sort=date', $data['state'], $data['signature'], self::handler($html, 'sort'));
        self::assertStringContainsString('sort=name', $data['streams']);
        self::assertNull($data['query']['values']['sort']);
    }

    public function testAnEventDoesNotReadTheQueryString(): void
    {
        [$html, $state, $signature] = $this->open('/url');

        $data = $this->send('/url?q=evil&page=9', $state, $signature, self::handler($html, 'click'));

        self::assertStringNotContainsString('evil', $data['streams']);
        self::assertNull($data['query']['values']['q']);
        self::assertNull($data['query']['values']['page']);
    }

    public function testABoundUrlPropertyGoesIntoTheQuery(): void
    {
        [$html, $state, $signature] = $this->open('/url');
        self::assertSame(1, preg_match('/name="q"[^>]*data-phem-bind="([^"]+)"/', $html, $m));

        $response = $this->event('/url', ['state' => $state, 'signature' => $signature, 'handler' => null, 'event' => ['type' => 'input', 'live' => true], 'bound' => [$m[1] => 'a b']]);
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('a b', $data['query']['values']['q']);
    }

    public function testTwoComponentsCannotKeepTheSameKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Two components of the page keep the query key "q" (#[Url]): <Catalog> and <Catalog>.');

        $this->fixtureKernel()->handle(\Symfony\Component\HttpFoundation\Request::create('/url-twice'), catch: false);
    }

    public function testAPageWithNoUrlPropertyHasNoQueryAttribute(): void
    {
        self::assertStringNotContainsString('data-phem-query-value', (string) $this->request('GET', '/bind')->getContent());
    }

    #[DataProvider('conversions')]
    public function testConvertsAQueryValue(string $property, mixed $value, mixed $expected): void
    {
        self::assertTrue(UrlQuery::convert((new \ReflectionProperty(self::class, $property))->getType(), $value, $out));
        self::assertSame($expected, $out);
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function conversions(): iterable
    {
        yield '1 is true' => ['bool', '1', true];
        yield '0 is false' => ['bool', '0', false];
        yield 'true' => ['bool', 'true', true];
        yield 'false for a nullable bool' => ['nullableBool', 'false', false];
        yield 'an empty value is null' => ['nullableBool', '', null];
        yield 'an int' => ['int', '12', 12];
    }

    public function testRejectsAQueryValueThatDoesNotFit(): void
    {
        self::assertFalse(UrlQuery::convert((new \ReflectionProperty(self::class, 'bool'))->getType(), 'yes'));
        self::assertFalse(UrlQuery::convert((new \ReflectionProperty(self::class, 'bool'))->getType(), ['1']));
        self::assertFalse(UrlQuery::convert((new \ReflectionProperty(self::class, 'int'))->getType(), ['k' => '1']));
    }

    /**
     * @param array<string, string|array<array-key, mixed>|null> $values
     */
    #[DataProvider('merges')]
    public function testMergesTheQueryIntoAUrl(string $url, array $values, string $expected): void
    {
        self::assertSame($expected, UrlQuery::merge($url, $values));
    }

    /** @return iterable<string, array{string, array<string, string|array<array-key, mixed>|null>, string}> */
    public static function merges(): iterable
    {
        yield 'a new key' => ['/s', ['q' => 'php'], '/s?q=php'];
        yield 'a key keeps its place' => ['/s?q=a&x=1', ['q' => 'b'], '/s?q=b&x=1'];
        yield 'null removes a key' => ['/s?x=1&q=a', ['q' => null], '/s?x=1'];
        yield 'no query' => ['/s?q=a', ['q' => null], '/s'];
        yield 'a list' => ['/s?tags[]=a&x=1&tags[]=b', ['tags' => ['c', 'd']], '/s?tags[]=c&tags[]=d&x=1'];
        yield 'an encoded list key' => ['/s?tags%5B%5D=a', ['tags' => []], '/s'];
        yield 'encoded as in the browser' => ['/s', ['q' => 'a b&c/é(!)'], '/s?q=a%20b%26c%2F%C3%A9(!)'];
        yield 'the fragment stays' => ['/s?q=a#top', ['q' => 'b'], '/s?q=b#top'];
        yield 'another key with the same start' => ['/s?qq=1', ['q' => 'a'], '/s?qq=1&q=a'];
        yield 'a nested value' => ['/s?f[a]=1&x=1&f[b][c]=2', ['f' => ['a' => '3', 'b' => ['c' => '4', 'd' => ['e']]]], '/s?f[a]=3&f[b][c]=4&f[b][d][]=e&x=1'];
        yield 'an encoded nested key' => ['/s?f%5Ba%5D=1', ['f' => ['a b' => 'c']], '/s?f[a%20b]=c'];
    }

    public function testAUrlPropertyMustBeState(): void
    {
        $this->expectExceptionMessage('must be a #[State] property');
        (new ComponentMetadata())->urlProperties((new class {
            #[Url] public string $q = '';
        })::class);
    }

    public function testTheQueryKeyHasOnlySafeCharacters(): void
    {
        $this->expectExceptionMessage('The query key "a.b"');
        (new ComponentMetadata())->urlProperties((new class {
            #[State, Url(as: 'a.b')] public string $q = '';
        })::class);
    }

    public function testTheTypeFitsAQueryString(): void
    {
        $this->expectExceptionMessage('must be a string, an int, a float, a bool, a backed enum, or an array of them');
        (new ComponentMetadata())->urlProperties((new class {
            #[State, Url] public ?\DateTimeImmutable $at = null;
        })::class);
    }

    public function testTheExceptValueFitsTheType(): void
    {
        $this->expectExceptionMessage('The "except" value of the #[Url] property "page" of');
        (new ComponentMetadata())->urlProperties((new class {
            #[State, Url(except: 'first')] public int $page = 1;
        })::class);
    }

    public function testTheExceptValueCanBeAnEnum(): void
    {
        $properties = (new ComponentMetadata())->urlProperties((new class {
            #[State, Url(except: Size::Large)] public ?Size $size = null;
        })::class);

        self::assertSame(Size::Large, $properties['size'][1]->except);
    }

    public function testTheExceptValueCanBeAnArrayWithKeys(): void
    {
        $properties = (new ComponentMetadata())->urlProperties((new class {
            /** @var array<string, string> */
            #[State, Url(except: ['status' => 'all'])] public array $filters;
        })::class);

        self::assertSame(['status' => 'all'], $properties['filters'][1]->except);
    }

    public function testTheValuesOfANestedValueFitAQueryString(): void
    {
        $this->expectExceptionMessage('The query string cannot keep a value of type "stdClass" (the #[Url] key "f")');
        UrlQuery::encode(['a' => ['b' => new \stdClass()]], 'f');
    }

    public function testTwoPropertiesCannotHaveTheSameKey(): void
    {
        $this->expectExceptionMessage('have the same query key "q"');
        (new ComponentMetadata())->urlProperties((new class {
            #[State, Url] public string $q = '';
            #[State, Url(as: 'q')] public string $query = '';
        })::class);
    }

    public function testTheMetadataHasTheKeysAndOptions(): void
    {
        $properties = (new ComponentMetadata())->urlProperties((new class {
            #[State, Url(as: 'q', history: true)] public string $query = '';
            #[State, Url] public Size $size = Size::Small;
            #[State] public int $other = 0;
        })::class);

        self::assertSame(['q', 'size'], array_keys($properties));
        self::assertSame('query', $properties['q'][0]->getName());
        self::assertTrue($properties['q'][1]->history);
    }

    /** @return array{0: string, 1: string, 2: string} the HTML, the state and its signature */
    private function open(string $uri): array
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m), $html);

        return [$html, html_entity_decode($m[1]), $m[2]];
    }

    /** @return array<string, mixed> */
    private function send(string $uri, string $state, string $signature, string $handler): array
    {
        $response = $this->event($uri, ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => 'click']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function handler(string $html, string $id): string
    {
        self::assertSame(1, preg_match('/<button id="'.$id.'" data-phem-on-click="([^"]+)"/', $html, $m), $id);

        return $m[1];
    }

    /** @return array<string, mixed> the query attribute of the root element */
    private static function query(string $html): array
    {
        self::assertSame(1, preg_match('/data-phem-query-value="([^"]*)"/', $html, $m), 'no query attribute');

        return json_decode(html_entity_decode($m[1]), true, 512, \JSON_THROW_ON_ERROR);
    }
}
