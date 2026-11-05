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

use Phem\Async\AsyncDispatcher;
use Phem\Async\AsyncHandlerMessage;
use Phem\Async\AsyncMiddleware;
use Phem\Async\Promise;
use Phem\Attribute\Async;
use Phem\Event;
use Phem\Http\EventReplay;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AsyncMiddlewareTest extends TestCase
{
    public function testAnAsyncMethodReturnsItsPromise(): void
    {
        $component = new AsyncHandlers();

        self::assertInstanceOf(Promise::class, $this->handle($component->export(...)));
    }

    public function testAnAsyncMethodThatReturnsNoPromiseThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[Async] handler "Phem\Tests\Runtime\AsyncHandlers::broken()" must return a Phem\Async\Promise, "null" given.');

        $this->handle(new AsyncHandlers()->broken(...));
    }

    public function testAPromiseOfAMethodWithNoAsyncThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('returns a Phem\Async\Promise, but it has no #[Phem\Attribute\Async] attribute');

        $this->handle(new AsyncHandlers()->hidden(...));
    }

    public function testAClosureCanReturnThePromiseOfAnAsyncMethod(): void
    {
        $component = new AsyncHandlers();

        self::assertInstanceOf(Promise::class, $this->handle(static fn (): Promise => $component->export()));
        self::assertNull($this->handle(static fn () => null));
    }

    public function testTheMessageHasTheEventAndTheRouteOfTheRequest(): void
    {
        $bus = new SpyBus();
        $request = self::request();
        $request->attributes->add(['_route' => 'async', '_route_params' => ['room' => 'lobby'], 'room' => 'lobby']);

        $this->handle(new AsyncHandlers()->export(...), $bus, $request);

        [$message] = $bus->messages;
        self::assertSame(['App\\Ui\\HomePage', '{"state":1}', 'phem0123456789ab.export'], [$message->page, $message->state, $message->handler]);
        self::assertSame(['room' => 'lobby', '_route' => 'async', '_route_params' => ['room' => 'lobby']], $message->route);
        self::assertTrue($message->isSignedWith('secret'));
    }

    public function testAnAsyncMethodWithFilesDoesNotRun(): void
    {
        $request = self::request();
        $request->files->set('photo', new UploadedFile(__FILE__, 'photo.jpg', test: true));
        $component = new AsyncHandlers();

        try {
            $this->handle($component->export(...), request: $request);
            self::fail('The handler ran.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot get the files of a form', $e->getMessage());
        }

        self::assertSame(0, $component->calls, 'the check runs before the handler');
    }

    public function testAnAsyncMethodWithNoMessengerDoesNotRun(): void
    {
        $component = new AsyncHandlers();

        try {
            new AsyncMiddleware(new AsyncDispatcher(null, 'secret'), self::stack(self::request()))->handle($component->export(...), new Event('click'), $component->export(...));
            self::fail('The handler ran.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('needs Symfony Messenger', $e->getMessage());
        }

        self::assertSame(0, $component->calls);
    }

    private function handle(\Closure $handler, SpyBus $bus = new SpyBus(), ?Request $request = null): mixed
    {
        return new AsyncMiddleware(new AsyncDispatcher($bus, 'secret'), self::stack($request ?? self::request()))->handle($handler, new Event('click'), $handler);
    }

    private static function stack(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private static function request(): Request
    {
        $request = Request::create('https://localhost/async/lobby', 'POST');
        $request->attributes->set(EventReplay::ATTRIBUTE, new EventReplay('App\\Ui\\HomePage', '{"state":1}', [], 'phem0123456789ab.export', ['type' => 'click']));

        return $request;
    }
}

final class SpyBus implements MessageBusInterface
{
    /** @var list<AsyncHandlerMessage> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        \assert($message instanceof AsyncHandlerMessage);
        $this->messages[] = $message;

        return Envelope::wrap($message, $stamps);
    }
}

final class AsyncHandlers
{
    public int $calls = 0;

    #[Async]
    public function export(): Promise
    {
        ++$this->calls;

        return new Promise(static function (): void {});
    }

    #[Async]
    public function broken(): mixed
    {
        return null;
    }

    public function hidden(): Promise
    {
        return new Promise(static function (): void {});
    }
}
