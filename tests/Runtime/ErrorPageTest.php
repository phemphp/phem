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

use Phem\Http\ErrorPageRenderer;
use Phem\Http\ErrorPages;
use Phem\Http\StatelessPageRenderer;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The error pages of the fixture app (app/src/Ui/Errors/ErrorPages.phem). The `errors`
 * environment has `show_exceptions: false`, as the e2e tests of an app. The kernel runs in the
 * web mode, as WebTestCase does: in the CLI mode, Symfony renders an error with CliErrorRenderer.
 */
final class ErrorPageTest extends TestCase
{
    use FixtureApp {
        tearDown as private shutdownKernel;
    }

    private bool $runtimeModeSet = false;

    protected function setUp(): void
    {
        if (!isset($_SERVER['APP_RUNTIME_MODE'])) {
            $_SERVER['APP_RUNTIME_MODE'] = 'web=1';
            $this->runtimeModeSet = true;
        }
    }

    protected function tearDown(): void
    {
        $this->shutdownKernel();

        if ($this->runtimeModeSet) {
            unset($_SERVER['APP_RUNTIME_MODE']);
        }
    }

    public function testAnErrorShowsThePageOfItsStatusInItsSection(): void
    {
        $response = $this->errors()->request('GET', '/errors/nothing');
        $html = (string) $response->getContent();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">", $html);
        self::assertStringContainsString('<title>Not here</title>', $html);
        self::assertStringContainsString('>Not here</h1>', $html);
    }

    public function testThePageOfAllTheStatusesKeepsTheStatusAndTheHeaders(): void
    {
        $response = $this->errors()->request('GET', '/errors/throw/429');
        $html = (string) $response->getContent();

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('<h1>429 Too Many Requests</h1>', $html);
        self::assertStringContainsString('<p id="retry-after">Retry after 60 seconds</p>', $html);
    }

    public function testAServerErrorDoesNotShowTheMessageOfItsException(): void
    {
        $response = $this->errors()->request('GET', '/errors/throw/500?page=2');
        $html = (string) $response->getContent();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('<h1>500 Internal Server Error</h1>', $html);
        self::assertStringContainsString('<a id="retry" href="/errors/throw/500?page=2">Try again</a>', $html, 'the Request of the page that failed');
        self::assertStringNotContainsString('A secret message.', $html);
    }

    public function testALongerPathWinsOverAStatus(): void
    {
        $response = $this->errors()->request('GET', '/errors/admin/nothing');
        $html = (string) $response->getContent();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<h1>Admin error 404</h1>', $html);
        self::assertStringContainsString('<p id="environment">errors</p>', $html, 'the services of a function page');
    }

    public function testAnErrorPageHasNoState(): void
    {
        $html = (string) $this->errors()->request('GET', '/errors/nothing')->getContent();

        self::assertStringContainsString('data-controller="phem"', $html);
        self::assertStringNotContainsString('data-phem-state-value', $html);
        self::assertStringNotContainsString('data-phem-signature-value', $html);
        self::assertStringNotContainsString('phem-root-', $html, 'no token of the state attributes');
    }

    public function testASuspenseRendersInTheErrorPage(): void
    {
        $html = (string) $this->errors()->request('GET', '/errors/suspense/throw/500')->getContent();

        self::assertStringContainsString('<p id="tips" data-phem-c="', $html);
        self::assertStringNotContainsString('Loading', $html);
        self::assertStringNotContainsString('data-phem-streaming', $html);
    }

    public function testAnErrorWithNoErrorPageGetsTheErrorOfSymfony(): void
    {
        $response = $this->errors()->request('GET', '/nothing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('<!-- No route found for &quot;GET http://localhost/nothing&quot;', (string) $response->getContent(), 'the debug page');
    }

    public function testAPhemEventGetsTheErrorOfSymfony(): void
    {
        $response = $this->errors()->request('GET', '/errors/nothing', server: ['HTTP_X_PHEM_EVENT' => '1']);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('<!-- No route found', (string) $response->getContent());
    }

    public function testTheDebugModeShowsTheException(): void
    {
        $response = $this->request('GET', '/errors/nothing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('<!-- No route found', (string) $response->getContent());
    }

    public function testThePreviewShowsTheErrorPageInTheDebugMode(): void
    {
        $response = $this->request('GET', '/_error/503');

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('<h1>Back soon</h1>', (string) $response->getContent());
    }

    public function testThePathParameterOfThePreviewChoosesTheSection(): void
    {
        $response = $this->request('GET', '/_error/404?path=/errors/admin');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<h1>Admin error 404</h1>', (string) $response->getContent());
    }

    public function testThePreviewOfAStatusWithNoPageShowsThePageOfSymfony(): void
    {
        $response = $this->request('GET', '/_error/404');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('The server returned a "404 Not Found".', (string) $response->getContent());
    }

    public function testAnErrorPageGetsTheExceptionForAParameterWithTheTypeOfAnException(): void
    {
        $html = (string) $this->errors()->request('GET', '/errors/throwable/nothing')->getContent();

        self::assertStringContainsString('>Symfony\Component\HttpKernel\Exception\NotFoundHttpException</p>', $html);
    }

    public function testAnErrorPageCanBeAPlainDocument(): void
    {
        $response = $this->errors()->request('GET', '/errors/plain/nothing');
        $html = (string) $response->getContent();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\"", $html);
        self::assertStringContainsString('<h1>Plain 404</h1>', $html);
        self::assertStringNotContainsString('data-controller="phem"', $html);
    }

    public function testAFailedErrorPageThrowsInTheDebugMode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The error page failed.');

        $this->errors()->request('GET', '/errors/broken/throw/500');
    }

    public function testAFailedErrorPageGivesTheErrorOfSymfonyInProduction(): void
    {
        $logger = new BufferingLogger();
        $request = Request::create('/errors/broken/nothing');
        $requests = new RequestStack([$request]);
        $inner = $this->service('phem.error_page_renderer.inner', ErrorRendererInterface::class);
        $renderer = new ErrorPageRenderer($inner, $this->service('phem.error_pages', ErrorPages::class), $this->service('phem.stateless_page_renderer', StatelessPageRenderer::class), $requests, logger: $logger);

        $error = $renderer->render(new NotFoundHttpException());

        self::assertSame(404, $error->getStatusCode());
        self::assertStringStartsWith('<!--  (404 Not Found) -->', $error->getAsString(), 'the page of Symfony');
        [[$level, $message, $context]] = $logger->cleanLogs();
        self::assertSame(['error', 'The error page "{page}" failed: {message}', 'App\\Ui\\Errors\\BrokenErrorPage', 'The error page failed.'], [$level, $message, $context['page'], $context['message']]);
    }

    public function testWithNoRequestTheErrorPageRendersForTheRootPath(): void
    {
        // As `error:dump` does.
        $this->kernel = new TestKernel('errors', true);
        $this->kernel->boot();
        $requests = $this->service('request_stack', RequestStack::class);

        $error = $this->service('error_renderer.html', ErrorRendererInterface::class)->render(new ServiceUnavailableHttpException());

        self::assertSame(503, $error->getStatusCode());
        self::assertStringContainsString('<h1>Back soon</h1>', $error->getAsString());
        self::assertStringContainsString('<p id="path">/</p>', $error->getAsString());
        self::assertStringContainsString('<p id="host">https://shop.example</p>', $error->getAsString(), 'the host of the router');
        self::assertNull($requests->getCurrentRequest(), 'the request of the render goes away');
    }

    public function testTheKernelInProductionShowsTheErrorPagesAndFallsBackWhenOneFails(): void
    {
        $this->kernel = new TestKernel('nodebug', false);
        $this->kernel->boot();

        $response = $this->request('GET', '/errors/nothing');
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('>Not here</h1>', (string) $response->getContent(), '`show_exceptions` is the debug mode of the kernel');

        $response = $this->request('GET', '/errors/broken/throw/500');
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('The server returned a "500 Internal Server Error".', (string) $response->getContent());
    }

    public function testAnErrorPageWithNoMatchingStatusStillMakesTheRightStatus(): void
    {
        $error = $this->errors()->service('error_renderer.html', ErrorRendererInterface::class);
        $requests = $this->service('request_stack', RequestStack::class);
        $requests->push(Request::create('/errors/nothing'));

        self::assertSame(418, $error->render(HttpException::fromStatusCode(418))->getStatusCode());
        self::assertStringContainsString('418 I&#039;m a teapot', $error->render(HttpException::fromStatusCode(418))->getAsString());
    }

    private function errors(): self
    {
        $this->kernel = new TestKernel('errors', true);
        $this->kernel->boot();

        return $this;
    }
}
