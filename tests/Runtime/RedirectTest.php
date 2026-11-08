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

use App\Ui\Redirect\RedirectSwitch;
use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase
{
    use FixtureApp;

    public function testHandlerThatReturnsARedirectResponseSendsItsUrl(): void
    {
        $html = (string) $this->request('GET', '/leave')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/data-phem-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/leave', [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true), 'no streams and no state: the page does not render again');
    }

    public function testHandlerThatReturnsARedirectSendsTheUrlOfThePage(): void
    {
        self::assertSame(['redirect' => '/about'], $this->json('/go', '#about'), 'a function page');
        self::assertSame(['redirect' => '/items/5?tab=notes'], $this->json('/go', '#item'), 'a class page, with a route value and the query string');
    }

    public function testHandlerThatRedirectsDropsItsBrowserEvents(): void
    {
        $response = $this->click('/notify', '#leave');

        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true));
    }

    public function testResponseHasTheBrowserEventsOfTheHandler(): void
    {
        $data = json_decode((string) $this->click('/notify', '#save')->getContent(), true);

        self::assertIsArray($data);
        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Saved 1']], ['name' => 'saved', 'detail' => 1]], $data['events']);
        self::assertSame([], json_decode((string) $this->click('/notify', '#quiet')->getContent(), true)['events'] ?? null);
    }

    public function testResponseHasTheBrowserEventsOfTheInjectedDispatcher(): void
    {
        $data = json_decode((string) $this->click('/notify', '#announce')->getContent(), true);

        self::assertIsArray($data);
        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Announced 1']], ['name' => 'announced', 'detail' => 'Hello']], $data['events']);
    }

    public function testFunctionPageThatReturnsARedirectAnswersTheGetWithA302(): void
    {
        $response = $this->request('GET', '/verify/abc');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/about', $response->headers->get('Location'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'), 'the headers of the RedirectTo');
        self::assertStringNotContainsString('data-phem-state-value', (string) $response->getContent(), 'the page does not render');
    }

    public function testFunctionPageWithNoMarkupRedirects(): void
    {
        $response = $this->request('GET', '/away');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/about?from=away', $response->headers->get('Location'));
    }

    public function testFunctionPageRendersWhenItReturnsMarkup(): void
    {
        $response = $this->request('GET', '/verify/void');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('This link is void.</p>', (string) $response->getContent());
    }

    public function testMountOfAClassPageThatReturnsARedirectAnswersTheGetWithA302(): void
    {
        $response = $this->request('GET', '/guard/closed');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/items/7', $response->headers->get('Location'));

        $response = $this->request('GET', '/guard/open');

        self::assertSame(200, $response->getStatusCode(), 'mount() returns null: the page renders');
        self::assertMatchesRegularExpression('/<p id="count"[^>]*>1<\/p>/', (string) $response->getContent());
    }

    public function testMountOfAPageThatRedirectsInAnEventSendsTheUrl(): void
    {
        self::assertSame(['redirect' => '/about'], $this->json('/closing', '#close'), 'the page has no #[State]: its mount() runs again after the handler');
    }

    public function testFunctionPageDoesNotRedirectAfterTheHandlerOfAChild(): void
    {
        try {
            $data = $this->json('/switch', '#switch');
        } finally {
            RedirectSwitch::$on = false;
        }

        // In debug, the full render of the check redirects, but the event renders only the child.
        self::assertArrayNotHasKey('redirect', $data);
        self::assertStringContainsString('Switch 1', json_encode($data['streams'], \JSON_THROW_ON_ERROR));
    }

    public function testMountOfAComponentThatIsNotAPageCannotRedirect(): void
    {
        $response = $this->request('GET', '/redirecting-child');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('returns a RedirectTo, but the component is not a page.', (string) $response->getContent());
    }

    public function testRedirectOfAHandlerSendsItsHeaders(): void
    {
        $response = $this->click('/go-with-headers', '#leave');

        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true));
        self::assertSame('yes', $response->headers->get('X-Leave'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'), 'the Cache-Control of the app');
        self::assertSame('application/json', $response->headers->get('Content-Type'), 'not the type of the redirect response');
    }

    public function testRedirectResponseOfAHandlerSendsItsCookies(): void
    {
        $response = $this->click('/leave', '#leave');

        self::assertSame(['redirect' => '/about'], json_decode((string) $response->getContent(), true));
        self::assertSame(['left'], array_map(static fn (\Symfony\Component\HttpFoundation\Cookie $c): string => $c->getName(), $response->headers->getCookies()));
    }

    /** @return array<string, mixed> */
    private function json(string $uri, string $id): array
    {
        $response = $this->click($uri, $id);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function click(string $uri, string $id): \Symfony\Component\HttpFoundation\Response
    {
        $html = (string) $this->request('GET', $uri)->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/id="'.substr($id, 1).'" data-phem-on-click="([^"]+)"/', $html, $h));

        return $this->event($uri, [
            'state' => html_entity_decode($m[1]),
            'signature' => $m[2],
            'handler' => $h[1],
            'event' => ['type' => 'click'],
        ]);
    }
}
