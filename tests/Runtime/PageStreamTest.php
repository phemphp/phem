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

use Phem\DependencyInjection\TestPublisherPass;
use Phem\EventListener\PageStreamListener;
use Phem\EventListener\StreamListener;
use Phem\Http\PageStream;
use Phem\Rendering\SuspenseStream;
use Phem\Rendering\Tree;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\Stream;
use Phem\Stream\Streams;
use Phem\Stream\Subscription;
use Phem\Stream\Topics;
use Phem\Test\Internal\StreamedContent;
use Phem\Tests\Fixtures\TestKernel;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * The streamed response of a page (see Phem\Http\PageStream): the boundaries render after the
 * kernel finished the request.
 */
final class PageStreamTest extends KernelTestCase
{
    private const HTML = '<html><body><div data-phem-streaming>Page</div></body></html>';

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheBoundariesRenderInTheRequestWithItsLocaleAndItsSession(): void
    {
        self::bootKernel();
        $requests = new RequestStack();
        $locales = self::locales();
        $request = Request::create('/page');
        $request->setLocale('fr');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $response = $this->stream($requests, $locales)->response(new Tree('page'), $request, self::HTML, static function () use ($requests, $locales, $request, $session): string {
            self::assertSame($request, $requests->getCurrentRequest());
            self::assertSame('fr', $locales->getLocale());
            $session->set('seen', true);

            return 'data-phem-state-value="{}"';
        });
        self::assertTrue($session->isStarted(), 'the session starts before the headers');

        // The session listener of Symfony saves the session: the listener of the stream starts it again.
        $session->save();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        new PageStreamListener()->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertTrue($session->isStarted());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $html = StreamedContent::of($response);
        self::assertSame('<html><body><div data-phem-streaming>Page</div><template data-phem-root-state data-phem-state-value="{}"></template></body></html>', $html);
        self::assertNull($requests->getCurrentRequest());
        self::assertSame('en', $locales->getLocale());
        self::assertFalse($session->isStarted(), 'the stream saves the session at its end');
        self::assertTrue($session->get('seen'));
    }

    public function testTheListenerDoesNotChangeAPageWithNoStream(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $response = new Response('page');
        $response->headers->set('Cache-Control', 'public, max-age=60');

        new PageStreamListener()->onKernelResponse(new ResponseEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
    }

    public function testAFailedStreamEndsThePageWithNoStateAndLogsTheError(): void
    {
        self::bootKernel();
        $requests = new RequestStack();
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = $level.': '.$message;
            }
        };

        $response = $this->stream($requests, logger: $logger)->response(new Tree('page'), Request::create('/page'), self::HTML, static fn (): string => throw new \RuntimeException('No state.'));

        self::assertSame('<html><body><div data-phem-streaming>Page</div><template data-phem-root-state data-phem-stream-error></template></body></html>', StreamedContent::of($response));
        self::assertSame(['critical: [phem] The stream of the page "{page}" failed: the page has no events. No state.'], $logger->logs);
        self::assertNull($requests->getCurrentRequest());
    }

    public function testInDebugTheErrorGoesOnAfterThePage(): void
    {
        self::bootKernel();
        $chunks = [];
        $response = $this->stream(debug: true)->response(new Tree('page'), Request::create('/page'), self::HTML, static fn (): string => throw new \RuntimeException('No state.'));

        try {
            StreamedContent::of($response, static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            });
            self::fail('The error goes on.');
        } catch (\RuntimeException $e) {
            self::assertSame('No state.', $e->getMessage());
        }

        self::assertStringEndsWith('<template data-phem-root-state data-phem-stream-error></template></body></html>', implode('', $chunks));
    }

    public function testThePushesOfTheBoundariesGoAfterTheResponse(): void
    {
        self::bootKernel();
        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        $streams = static::getContainer()->get('phem.streams');
        self::assertInstanceOf(MemoryPublisher::class, $publisher);
        self::assertInstanceOf(Streams::class, $streams);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $before = \count($publisher->updates());

        // A mount() in a streamed boundary, after the response event.
        $streams->add('topic', new Stream('refresh', null));
        new StreamListener($streams, new Subscription(new Topics()))->onKernelTerminate(new TerminateEvent($kernel, Request::create('/page'), new Response()));

        self::assertCount($before + 1, $publisher->updates());
    }

    private function stream(?RequestStack $requests = null, ?LocaleAwareInterface $locales = null, ?AbstractLogger $logger = null, bool $debug = false): PageStream
    {
        $boundaries = static::getContainer()->get('phem.suspense_stream');
        self::assertInstanceOf(SuspenseStream::class, $boundaries);

        return new PageStream($boundaries, $requests, $locales, $logger, $debug);
    }

    private static function locales(): LocaleAwareInterface
    {
        return new class implements LocaleAwareInterface {
            private string $locale = 'en';

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }

            public function getLocale(): string
            {
                return $this->locale;
            }
        };
    }
}
