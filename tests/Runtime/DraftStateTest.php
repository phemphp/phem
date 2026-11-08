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

use Phem\State\StateJson;
use Phem\Tests\Fixtures\Renders;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

/**
 * A draft object in a #[State] property (see Phem\State\StateObjects and the `draft` fixture
 * page): the state keeps its JSON value, a bound path sets its properties, and a change in place
 * renders the children that get it.
 *
 * With no `kernel.debug`: the dev-mode check renders the full page again (see SkipTest).
 */
final class DraftStateTest extends TestCase
{
    use FixtureApp;

    private string $html = '';
    private string $state = '';
    private string $signature = '';

    public function fixtureKernel(): TestKernel
    {
        if (null === $this->kernel) {
            $this->kernel = new TestKernel('nodebug', false);
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    protected function setUp(): void
    {
        Renders::reset();

        $this->html = (string) $this->request('GET', '/draft')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $this->html, $m), $this->html);
        $this->state = html_entity_decode($m[1]);
        $this->signature = $m[2];

        Renders::$counts = [];
    }

    public function testTheStateKeepsTheJsonValueOfTheDraft(): void
    {
        self::assertSame(['name' => 'Rex', 'age' => null, 'size' => 's', 'born' => '2020-05-17T00:00:00+02:00', 'home' => ['city' => 'Oslo'], 'kind' => 'dog'], $this->formState()['draft']);
        self::assertStringContainsString('value="Rex"', $this->html);
        self::assertStringContainsString('value="2020-05-17"', $this->html, 'a date input shows the date in its format');
        self::assertMatchesRegularExpression('#<p id="card"[^>]*>Rex from Oslo</p>#', $this->html);
    }

    public function testABoundPathSetsAPropertyOfTheDraft(): void
    {
        $data = $this->send(null, [
            $this->bindKey('name') => 'Bella',
            $this->bindKey('age') => '4',
            $this->bindKey('size') => 'l',
            $this->bindKey('born') => '2022-01-31',
            $this->bindKey('city') => 'Bergen',
        ]);

        $draft = $this->formState()['draft'];
        self::assertSame(['name' => 'Bella', 'age' => 4, 'size' => 'l', 'born' => '2022-01-31T00:00:00+02:00', 'home' => ['city' => 'Bergen'], 'kind' => 'dog'], $draft);
        self::assertStringContainsString('Bella from Bergen', $data['streams'], 'the child gets the changed draft');
        self::assertSame(2, Renders::of('PetCard'), 'the first pass renders the subtree of the bound fields, and the second pass shows the change');
    }

    public function testABoundPathSetsOnlyAPropertyThatCanBeWritten(): void
    {
        $name = $this->bindKey('name');
        $fiber = substr($name, 0, (int) strpos($name, '.'));

        $this->send(null, [
            $this->bindKey('kind') => 'cat',
            $fiber.'.draft["other"]' => 'x',
            $this->bindKey('age') => 'abc',
            $this->bindKey('born') => 'not a date',
        ]);

        $draft = $this->formState()['draft'];
        self::assertSame('dog', $draft['kind'], 'a readonly property');
        self::assertArrayNotHasKey('other', $draft);
        self::assertNull($draft['age'], 'text for an int');
        self::assertSame('2020-05-17T00:00:00+02:00', $draft['born'], 'text for a date');
    }

    public function testAChangeInPlaceRendersTheChild(): void
    {
        $data = $this->send('rename', []);

        self::assertSame('Max', $this->formState()['draft']['name']);
        self::assertStringContainsString('Max from Oslo', $data['streams']);
        self::assertSame(1, Renders::of('PetCard'), 'the handler changed a copy: the child gets another object');
    }

    public function testAChildChangesTheDraftOfItsParent(): void
    {
        $data = $this->send('shout', []);

        self::assertSame('REX', $this->formState()['draft']['name'], 'the child changed the object of the parent state');
        self::assertStringContainsString('REX from Oslo', $data['streams'], 'the parent renders, and the other child gets the changed draft');
    }

    public function testABoundDateKeepsTheTimezoneOfTheCurrentDate(): void
    {
        $this->send(null, [$this->bindKey('born') => '2022-01-31']);

        self::assertSame('2022-01-31T00:00:00+02:00', $this->formState()['draft']['born']);
    }

    public function testAPathCannotGoIntoAnEnum(): void
    {
        $response = $this->request('GET', '/draft-enum-path');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('is the enum Phem\Tests\Fixtures\Size. Bind the enum itself', (string) $response->getContent());
    }

    public function testAPathGoesIntoAnObjectOnlyInAPropertyOfAClass(): void
    {
        $response = $this->request('GET', '/draft-array-object');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('is a Phem\Tests\Fixtures\PetDraft object. A path goes into an object only in a property whose type is a class', (string) $response->getContent());
    }

    public function testAnUnchangedDraftKeepsTheChild(): void
    {
        $data = $this->send('save', []);

        self::assertSame(1, $this->formState()['saves']);
        self::assertStringContainsString('Save 1', $data['streams']);
        self::assertSame(0, Renders::of('PetCard'), 'the draft did not change: the child gets the same object');
    }

    public function testTheErrorsOfTheDraftHaveThePathOfTheBoundField(): void
    {
        $data = $this->send(null, [$this->bindKey('name') => '', $this->bindKey('city') => 'X']);

        self::assertStringContainsString('Give a name.', $data['streams']);
        self::assertStringContainsString('Too short.', $data['streams']);
    }

    /** @return array<string, mixed> the state of the PetForm component */
    private function formState(): array
    {
        foreach (json_decode($this->state, true, 512, \JSON_THROW_ON_ERROR) as $state) {
            if (\is_array($state) && \array_key_exists('draft', $state)) {
                return $state;
            }
        }

        self::fail('No PetForm state.');
    }

    /** The key of the bound field with this name. */
    private function bindKey(string $name): string
    {
        self::assertSame(1, preg_match('/<input[^>]*name="'.preg_quote($name, '/').'"[^>]*data-phem-bind="([^"]+)"/', $this->html, $m), $name);

        return html_entity_decode($m[1]);
    }

    /**
     * @param string|null          $button the id of the button of the handler, or null for an event with only bound values
     * @param array<string, mixed> $bound
     *
     * @return array<string, mixed>
     */
    private function send(?string $button, array $bound): array
    {
        $handler = null;
        if (null !== $button) {
            self::assertSame(1, preg_match('/<button id="'.$button.'"[^>]*data-phem-on-click="([^"]+)"/', $this->html, $h), $button);
            $handler = $h[1];
        }

        $response = $this->event('/draft', ['state' => $this->state, 'signature' => $this->signature, 'handler' => $handler, 'event' => ['type' => null === $handler ? 'input' : 'click', 'live' => null === $handler], 'bound' => $bound]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->state = StateJson::apply($this->state, $data['changes']);
        $this->signature = $data['signature'];

        return $data;
    }
}
