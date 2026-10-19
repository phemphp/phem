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

use Phem\Bound;
use Phem\Dom\Attributes;
use Phem\Manifest\Manifest;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\State\BoundValues;
use Phem\Tests\Fixtures\Size;
use Phem\Twig\PhemRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `phem:bind`: the render of a bound field, the conversion of a value of the browser to the
 * type of the property, and the bound values of an event.
 */
final class BindTest extends TestCase
{
    use FixtureApp;

    public string $string = '';
    public ?string $nullableString = null;
    public int $int = 0;
    public ?int $nullableInt = null;
    public float $float = 0.0;
    public bool $bool = false;
    /** @var list<string> */
    public array $array = [];
    public Size $size = Size::Small;
    public ?Size $nullableSize = null;
    public int|string $intOrString = 0;
    public $untyped; // @phpstan-ignore missingType.property

    #[DataProvider('conversions')]
    public function testConvertsAValueOfTheBrowserToTheTypeOfTheProperty(string $property, mixed $value, mixed $expected): void
    {
        $type = (new \ReflectionProperty(self::class, $property))->getType();
        self::assertTrue(BoundValues::convert($type, $value, $out));
        self::assertSame($expected, $out);
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function conversions(): iterable
    {
        yield 'a string' => ['string', 'Ada', 'Ada'];
        yield 'an empty string' => ['string', '', ''];
        yield 'an empty nullable string stays a string' => ['nullableString', '', ''];
        yield 'a numeric string to an int' => ['int', '36', 36];
        yield 'a negative int' => ['int', '-4', -4];
        yield 'an empty field to null' => ['nullableInt', '', null];
        yield 'a numeric string to a float' => ['float', '1.5', 1.5];
        yield 'an int to a float' => ['float', 2, 2.0];
        yield 'a checkbox' => ['bool', true, true];
        yield 'a list of strings' => ['array', ['a', 'b'], ['a', 'b']];
        yield 'a case of an enum' => ['size', 'l', Size::Large];
        yield 'an empty field to a null enum' => ['nullableSize', '', null];
        yield 'a union keeps the type of the value' => ['intOrString', '3', '3'];
        yield 'no type' => ['untyped', 'x', 'x'];
    }

    #[DataProvider('rejections')]
    public function testRejectsAValueThatDoesNotFitTheType(string $property, mixed $value): void
    {
        $type = (new \ReflectionProperty(self::class, $property))->getType();
        self::assertFalse(BoundValues::convert($type, $value));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function rejections(): iterable
    {
        yield 'text for an int' => ['int', 'abc'];
        yield 'a float string for an int' => ['int', '1.5'];
        yield 'an empty field for a non-null int' => ['int', ''];
        yield 'a space in a number' => ['float', ' 1'];
        yield 'a string for a bool' => ['bool', '1'];
        yield 'a string for an array' => ['array', 'a'];
        yield 'a hash for an array' => ['array', ['k' => 'a']];
        yield 'a nested list' => ['array', [['a']]];
        yield 'an unknown case' => ['size', 'xl'];
        yield 'null for a non-null string' => ['string', null];
        yield 'a hash with no type' => ['untyped', ['k' => 'v']];
    }

    #[DataProvider('conversionsLike')]
    public function testConvertsAValueOfTheBrowserToTheTypeOfTheCurrentValue(mixed $current, mixed $value, mixed $expected): void
    {
        self::assertTrue(BoundValues::convertLike($current, $value, $out));
        self::assertSame($expected, $out);
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function conversionsLike(): iterable
    {
        yield 'a string' => ['', 'Ada', 'Ada'];
        yield 'a numeric string to an int' => [0, '36', 36];
        yield 'a numeric string to a float' => [0.5, '2', 2.0];
        yield 'a checkbox' => [false, true, true];
        yield 'a case of an enum' => [Size::Small, 'l', Size::Large];
        yield 'null takes the text' => [null, '36', '36'];
        yield 'a list of ints' => [[1, 2], ['3'], [3]];
        yield 'an empty list takes strings' => [[], ['3'], ['3']];
        yield 'a list of mixed items takes strings' => [[1, 'a'], ['3'], ['3']];
    }

    #[DataProvider('rejectionsLike')]
    public function testRejectsAValueThatDoesNotFitTheTypeOfTheCurrentValue(mixed $current, mixed $value): void
    {
        self::assertFalse(BoundValues::convertLike($current, $value));
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function rejectionsLike(): iterable
    {
        yield 'text for an int' => [0, 'abc'];
        yield 'an empty field for an int' => [0, ''];
        yield 'a string for a bool' => [false, '1'];
        yield 'an unknown case' => [Size::Small, 'xl'];
        yield 'a string for a list' => [[], 'a'];
        yield 'a list for a string' => ['', ['a']];
        yield 'text in a list of ints' => [[1], ['x']];
        yield 'a hash' => [null, ['k' => 'v']];
        yield 'an object' => [new \stdClass(), 'x'];
    }

    public function testRendersTheValueOfABoundField(): void
    {
        $runtime = new PhemRuntime(new Renderer(new Manifest('/nonexistent', ''), new MarkupResolver(new Environment(new ArrayLoader())), []));

        self::assertSame('value="&lt;Ada&gt;"', (string) $runtime->bind('name', '<Ada>', 'input'));
        self::assertSame('value=""', (string) $runtime->bind('age', null, 'input', 'number'));
        self::assertSame('value="l"', (string) $runtime->bind('size', Size::Large, 'input'));
        self::assertSame('checked', (string) $runtime->bind('agree', true, 'input', 'checkbox'));
        self::assertSame('', (string) $runtime->bind('agree', false, 'input', 'CHECKBOX'));
        self::assertSame('data-phem-bind-list checked', (string) $runtime->bind('tags', ['a', 'b'], 'input', 'checkbox', 'b'));
        self::assertSame('data-phem-bind-list', (string) $runtime->bind('tags', ['a'], 'input', 'checkbox', 'b'));
        self::assertSame('checked', (string) $runtime->bind('size', Size::Large, 'input', 'radio', 'l'));
        self::assertSame('', (string) $runtime->bind('size', Size::Large, 'input', 'radio', 's'));
        self::assertSame('', (string) $runtime->bind('plan', 'pro', 'select'), 'the options get `selected`');
        self::assertSame('selected', (string) Attributes::selected('pro', 'pro'));
        self::assertSame('', (string) Attributes::selected('pro', 'free'));
        self::assertSame('selected', (string) Attributes::selected(['a', 'b'], 'b'), 'a <select multiple>');
        self::assertSame('selected', (string) Attributes::selected(3, '3'));
        self::assertSame('', (string) Attributes::selected(null, ''));

        // A Bound prop of a child component: the value of the parent property.
        self::assertSame('value="Ada"', (string) $runtime->bind(new Bound('name', 'Ada'), new Bound('name', 'Ada'), 'input'));
        self::assertSame('checked', (string) $runtime->bind(new Bound('agree', true), null, 'input', 'checkbox'));
        self::assertSame('selected', (string) Attributes::selected(new Bound('plan', 'pro'), 'pro'));
        self::assertSame('Ada', Attributes::bindValue(new Bound('bio', 'Ada')), 'the text of a <textarea>');
    }

    public function testABindCannotComeFromASpread(): void
    {
        $runtime = new PhemRuntime(new Renderer(new Manifest('/nonexistent', ''), new MarkupResolver(new Environment(new ArrayLoader())), []));
        self::assertSame('data-phem-live="true"', (string) $runtime->attrs(['phem:live' => true]));
        self::assertSame('', (string) $runtime->attrs(['phem:live' => 'yes']));

        $this->expectException(\InvalidArgumentException::class);
        $runtime->attrs(['phem:bind' => 'name']);
    }

    public function testAnEventSetsTheBoundValuesBeforeTheHandler(): void
    {
        [$html, $state, $signature] = $this->openBind();
        $name = self::bindKey($html, 'name');
        self::assertSame(1, preg_match('/<form data-phem-on-submit="([^"]+)"/', $html, $m));

        $data = $this->send($state, $signature, $m[1], [$name => 'Ada', self::bindKey($html, 'age') => '36', self::bindKey($html, 'tag-a') => ['b']]);

        self::assertSame(['name' => 'Ada', 'age' => 36, 'tags' => ['b'], 'saves' => 1], array_intersect_key(self::profileState($data['state']), ['name' => 1, 'age' => 1, 'tags' => 1, 'saves' => 1]));
        self::assertStringContainsString('value="Ada"', $data['streams'], 'the second render shows the new value');
        self::assertStringContainsString('Ada, 36, not agreed, b,', $data['streams']);
    }

    public function testALiveEventHasNoHandler(): void
    {
        [$html, $state, $signature] = $this->openBind();
        $query = self::bindKey($html, 'query');

        $data = $this->send($state, $signature, null, [$query => 'php']);

        self::assertSame('php', self::profileState($data['state'])['query']);
        self::assertSame(0, self::profileState($data['state'])['saves']);
        self::assertStringContainsString('Results for php', $data['streams']);
        self::assertSame([], $data['events']);
    }

    public function testOnlyTheBoundPropertiesGetAValue(): void
    {
        [$html, $state, $signature] = $this->openBind();
        $name = self::bindKey($html, 'name');
        $fiber = substr($name, 0, (int) strpos($name, '.'));

        $data = $this->send($state, $signature, null, [
            $fiber.'.saves' => 99,
            'phem000000000000.name' => 'Eve',
            $name.'.x' => 'Eve',
            $name => ['Eve'],
            self::bindKey($html, 'age') => 'abc',
            self::bindKey($html, 'size') => 'xl',
        ]);

        $profile = self::profileState($data['state']);
        self::assertSame(0, $profile['saves'], 'a #[State] property with no bound field');
        self::assertSame('', $profile['name'], 'a list for a string');
        self::assertNull($profile['age'], 'text for an int');
        self::assertSame('s', $profile['size']);
    }

    public function testAnEventSetsTheValueOfABoundPath(): void
    {
        [$html, $state, $signature] = $this->openBind();
        $row = self::bindKey($html, 'row-1');
        self::assertStringEndsWith('.rows[1]["name"]', $row);
        self::assertStringContainsString('<option selected>1</option>', $html);

        $data = $this->send($state, $signature, null, [
            self::bindKey($html, 'status') => 'closed',
            self::bindKey($html, 'min') => '5',
            self::bindKey($html, 'ids') => ['2', '3'],
            $row => 'Grace',
        ]);

        $profile = self::profileState($data['state']);
        self::assertSame(['status' => 'closed', 'min' => 5, 'ids' => [2, 3]], $profile['filters']);
        self::assertSame([['name' => 'Ada'], ['name' => 'Grace']], $profile['rows']);
        self::assertStringContainsString('closed, 5, 2+3, Ada+Grace', $data['streams']);
    }

    public function testABoundPathSetsOnlyAKeyThatTheArrayHas(): void
    {
        [$html, $state, $signature] = $this->openBind();
        $status = self::bindKey($html, 'status');
        $fiber = substr($status, 0, (int) strpos($status, '.'));

        $data = $this->send($state, $signature, null, [
            $fiber.'.filters' => 'x',
            $fiber.'.filters["other"]' => 'x',
            $fiber.'.rows[2]["name"]' => 'x',
            $fiber.'.rows["1"]["name"]' => 'x',
            self::bindKey($html, 'min') => 'abc',
            self::bindKey($html, 'ids') => ['x'],
            $status => ['closed'],
        ]);

        $profile = self::profileState($data['state']);
        self::assertSame(['status' => 'open', 'min' => 0, 'ids' => [1, 2]], $profile['filters']);
        self::assertSame([['name' => 'Ada'], ['name' => 'Eve']], $profile['rows']);
    }

    public function testABoundPathNeedsTheKeyInTheValue(): void
    {
        $response = $this->request('GET', '/bind-missing-key');

        // With strict_variables, Twig fails first.
        self::assertSame(500, $response->getStatusCode());
        self::assertMatchesRegularExpression('/has no key \[(&quot;|")status(&quot;|")\]|Key (&quot;|")status(&quot;|") does not exist/', (string) $response->getContent());
    }

    public function testAChildFieldBindsThePropertyOfItsParent(): void
    {
        $html = (string) $this->request('GET', '/bind-child')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m), $html);
        [$state, $signature] = [html_entity_decode($m[1]), $m[2]];
        self::assertSame(1, preg_match('/<form data-phem-on-submit="([^"]+)"/', $html, $m));
        $save = $m[1];

        $email = self::bindKey($html, 'email');
        $fiber = substr($email, 0, (int) strpos($email, '.'));
        self::assertSame($fiber.'.email', $email, 'the key of the parent property');
        self::assertSame($fiber.'.note', self::bindKey($html, 'note'), 'through a function component');
        self::assertSame($fiber.'.plan', self::bindKey($html, 'plan'), 'in a class component with its own state');
        self::assertSame($fiber.'.address["city"]', self::bindKey($html, 'city'), 'a path');
        self::assertStringContainsString('value="Paris"', $html);
        self::assertStringContainsString('<option value="free" selected>Free</option>', $html);

        $response = $this->event('/bind-child', ['state' => $state, 'signature' => $signature, 'handler' => $save, 'event' => ['type' => 'submit'], 'bound' => [
            $email => 'ada@example.com',
            $fiber.'.note' => 'Hi',
            $fiber.'.plan' => 'pro',
            $fiber.'.address["city"]' => 'Rome',
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('ada@example.com, Hi, pro, Rome, saves 1', $data['streams']);
        self::assertStringContainsString('value="ada@example.com"', $data['streams'], 'the child field shows the new value');
        self::assertStringContainsString('<option value="pro" selected>Pro</option>', $data['streams']);
    }

    public function testAnEventWithAHandlerOfNullNeedsBoundValues(): void
    {
        [, $state, $signature] = $this->openBind();

        self::assertSame(400, $this->event('/bind', ['state' => $state, 'signature' => $signature, 'handler' => null, 'bound' => 'x'])->getStatusCode());
        self::assertSame(400, $this->event('/bind', ['state' => $state, 'signature' => $signature, 'bound' => []])->getStatusCode(), 'no handler key');
        self::assertSame(200, $this->event('/bind', ['state' => $state, 'signature' => $signature, 'handler' => null, 'bound' => []])->getStatusCode());
    }

    /** @return array{0: string, 1: string, 2: string} the HTML, the state and its signature */
    private function openBind(): array
    {
        $html = (string) $this->request('GET', '/bind')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m), $html);

        return [$html, html_entity_decode($m[1]), $m[2]];
    }

    /**
     * @param array<string, mixed> $bound
     *
     * @return array<string, mixed>
     */
    private function send(string $state, string $signature, ?string $handler, array $bound): array
    {
        $response = $this->event('/bind', ['state' => $state, 'signature' => $signature, 'handler' => $handler, 'event' => ['type' => null === $handler ? 'input' : 'submit', 'live' => null === $handler], 'bound' => $bound]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** The key of the bound field with this name. */
    private static function bindKey(string $html, string $name): string
    {
        self::assertSame(1, preg_match('/<(?:input|select|textarea)[^>]*name="'.preg_quote($name, '/').'"[^>]*data-phem-bind="([^"]+)"/', $html, $m), $name);

        return html_entity_decode($m[1]);
    }

    /** @return array<string, mixed> the state of the Profile component */
    private static function profileState(string $json): array
    {
        foreach (json_decode($json, true, 512, \JSON_THROW_ON_ERROR) as $state) {
            if (\is_array($state) && \array_key_exists('saves', $state)) {
                return $state;
            }
        }

        self::fail('No Profile state.');
    }
}
