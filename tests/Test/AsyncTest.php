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

namespace Phem\Tests\Test;

use Phem\Async\AsyncHandler;
use Phem\Async\AsyncHandlerMessage;
use Phem\Async\MemoryBus;
use Phem\DependencyInjection\TestAsyncPass;
use Phem\DependencyInjection\TestPublisherPass;
use Phem\Stream\MemoryPublisher;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** The #[Async] handlers (tests/Fixtures/app/src/page/async.phpm). */
final class AsyncTest extends KernelTestCase
{
    use InteractsWithPhem;

    public static function setUpBeforeClass(): void
    {
        TestKernel::compileSources();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheEventRendersAndTheWorkRunsOnlyInTheWorker(): void
    {
        $other = $this->visit('/async/lobby');
        $page = $this->visit('/async/lobby')
            ->fill('input[name=format]', 'pdf')
            ->submit('form', ['name' => 'report'])
            // The method body ran in the request: the event renders its state.
            ->assertSelectorTextContains('#status', 'pending')
            ->receive()
            ->assertSelectorTextContains('#notice', 'Started')
            // The work did not run.
            ->assertDontSee('report.pdf');

        // The worker gets the prop, the bound value and the form of the event.
        $page->settle()->receive()->assertSelectorTextContains('#result', 'report.pdf');
        $other->receive()->assertSelectorTextContains('#result', 'report.pdf');
    }

    public function testThePushOfTheMethodBodyGoesOutOneTime(): void
    {
        $this->visit('/async/lobby')->submit('form', ['name' => 'report'])->settle();

        $started = array_filter($this->updates(), static fn (array $update): bool => str_contains($update['data'], 'Started'));
        self::assertCount(1, $started);
    }

    public function testAClosureOfTheRenderRunsWithItsValues(): void
    {
        $this->visit('/async/lobby')
            ->click('#ship-2')
            ->settle()
            ->receive()
            ->assertSelectorTextContains('#result', 'Shipped 2');
    }

    public function testAWorkThatThrowsDropsItsPushes(): void
    {
        $page = $this->visit('/async/lobby')->click('#crash');
        $before = \count($this->updates());

        try {
            $page->settle();
            self::fail('The work did not throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('The work failed.', $e->getMessage());
        }

        self::assertCount($before, $this->updates());
    }

    public function testTheWorkerRejectsAMessageWithNoValidSignature(): void
    {
        $this->visit('/async/lobby')->click('#ship-1');

        $bus = static::getContainer()->get(TestAsyncPass::ID);
        $handler = static::getContainer()->get('phem.async.handler');
        \assert($bus instanceof MemoryBus && $handler instanceof AsyncHandler);
        [$message] = $bus->take();

        $forged = new AsyncHandlerMessage($message->page, $message->url, $message->route, $message->state, $message->bound, $message->handler, $message->event, $message->token, $message->signature);
        $handler($forged);
        $changed = new AsyncHandlerMessage($message->page, $message->url, $message->route, $message->state, $message->bound, 'other', $message->event, $message->token, $message->signature);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $handler($changed);
    }

    public function testSettleFailsWhenNoWorkWaits(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No #[Async] work waits');

        $this->visit('/async/lobby')->settle();
    }

    public function testAMountedComponentCannotRunAnAsyncHandler(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('In a test, use visit() and settle(), not mount().');

        $this->mount('App\Page\AsyncExport', ['room' => 'lobby'])->click('#ship-1');
    }

    /** @return list<array{topics: list<string>, data: string, private: bool}> */
    private function updates(): array
    {
        $publisher = static::getContainer()->get(TestPublisherPass::ID);
        \assert($publisher instanceof MemoryPublisher);

        return $publisher->updates();
    }
}
