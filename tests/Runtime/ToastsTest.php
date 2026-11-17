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

use Phem\BrowserEventDispatcher;
use Phem\Event;
use Phem\Toast;
use Phem\Toasts;
use Phem\ToastType;
use PHPUnit\Framework\TestCase;

final class ToastsTest extends TestCase
{
    public function testShowDispatchesAToastWithItsType(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $toasts = new Toasts($dispatcher);
        $toasts->show('Saved');
        $toasts->show('No stock', ToastType::Error);

        // No type by default, as in Base UI: the toast gets no `data-type`.
        self::assertSame([
            ['name' => 'toast', 'detail' => ['message' => 'Saved']],
            ['name' => 'toast', 'detail' => ['message' => 'No stock', 'type' => 'error']],
        ], $dispatcher->dispatched());
    }

    public function testEachTypeHasAMethod(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $toasts = new Toasts($dispatcher);
        $toasts->info('a');
        $toasts->success('b');
        $toasts->warning('c');
        $toasts->error('d');

        self::assertSame(['info', 'success', 'warning', 'error'], array_map(static fn (array $e): string => $e['detail']['type'], $dispatcher->dispatched()));
    }

    public function testADurationGoesInTheDetail(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        new Toasts($dispatcher)->warning('Check the stock', 0);

        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Check the stock', 'type' => 'warning', 'duration' => 0]]], $dispatcher->dispatched());
    }

    public function testATitleGoesInTheDetail(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $toasts = new Toasts($dispatcher);
        $toasts->success('The file is in your downloads.', title: 'Export complete');
        $toasts->show('No stock', ToastType::Error, 0, 'Order');

        self::assertSame([
            ['name' => 'toast', 'detail' => ['message' => 'The file is in your downloads.', 'type' => 'success', 'title' => 'Export complete']],
            ['name' => 'toast', 'detail' => ['message' => 'No stock', 'type' => 'error', 'duration' => 0, 'title' => 'Order']],
        ], $dispatcher->dispatched());
    }

    public function testTheOptionsOfBaseUiGoInTheDetail(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $toasts = new Toasts($dispatcher);
        $toasts->show('You can undo this action.', ToastType::Success, 10000, 'Action performed', id: 'delete-7', action: 'Undo', data: ['item' => 7]);
        $toasts->show('Copied', duration: 1500, anchor: 'copy-button', viewport: 'anchored');

        self::assertSame([
            ['name' => 'toast', 'detail' => ['message' => 'You can undo this action.', 'type' => 'success', 'duration' => 10000, 'title' => 'Action performed', 'id' => 'delete-7', 'action' => 'Undo', 'data' => ['item' => 7]]],
            ['name' => 'toast', 'detail' => ['message' => 'Copied', 'duration' => 1500, 'anchor' => 'copy-button', 'viewport' => 'anchored']],
        ], $dispatcher->dispatched());
    }

    public function testAnEventShowsAToastWithTheOptions(): void
    {
        $event = new Event('click');
        $event->toast('Draft saved', id: 'save-status');

        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Draft saved', 'id' => 'save-status']]], $event->dispatcher->dispatched());
    }

    public function testShowRejectsAnEmptyOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The action of a toast cannot be empty. Give null for a toast with no action.');

        new Toasts(new BrowserEventDispatcher())->show('Saved', action: ' ');
    }

    public function testShowRejectsDataThatIsNotAMapOfScalars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The data of a toast is an array of names and texts, numbers, booleans or null');

        new Toasts(new BrowserEventDispatcher())->show('Saved', data: ['user' => ['id' => 7]]);
    }

    public function testShowRejectsAListAsData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The data of a toast is an array of names and texts, numbers, booleans or null');

        new Toasts(new BrowserEventDispatcher())->show('Saved', data: ['a', 'b']);
    }

    public function testShowRejectsAnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The title of a toast cannot be empty. Give null for a toast with no title.');

        new Toasts(new BrowserEventDispatcher())->info('Saved', title: ' ');
    }

    public function testAnEventShowsAToastWithItsDispatcher(): void
    {
        $event = new Event('click');
        $event->toast('Saved', ToastType::Success, 8000);

        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Saved', 'type' => 'success', 'duration' => 8000]]], $event->dispatcher->dispatched());
    }

    public function testShowRejectsAnEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The message of a toast cannot be empty.');

        new Toasts(new BrowserEventDispatcher())->show(' ');
    }

    public function testShowRejectsANegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The duration of a toast is from 0 to 2147483647 ms, -1 given.');

        new Toasts(new BrowserEventDispatcher())->error('No stock', -1);
    }

    public function testShowRejectsADurationThatTheBrowserCannotWait(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        new Toasts($dispatcher)->info('Kept', Toasts::MAX_DURATION);

        self::assertSame(Toasts::MAX_DURATION, $dispatcher->dispatched()[0]['detail']['duration']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The duration of a toast is from 0 to 2147483647 ms, 2147483648 given. Give 0 to keep the toast until the user closes it.');

        new Toasts($dispatcher)->info('Gone', Toasts::MAX_DURATION + 1);
    }

    public function testFromDetailReadsTheDetailAsTheController(): void
    {
        self::assertEquals(new Toast('Saved', null), Toast::fromDetail('Saved'));
        self::assertEquals(new Toast('No stock', ToastType::Error, 0), Toast::fromDetail(['message' => 'No stock', 'type' => 'error', 'duration' => 0]));
        // A detail with no type, or with a type that the controller does not know, has no type.
        self::assertEquals(new Toast('Odd', null), Toast::fromDetail(['message' => 'Odd', 'type' => 'danger']));
        self::assertEquals(new Toast('42', null), Toast::fromDetail(['message' => 42]));
        self::assertEquals(new Toast('["a","b"]', null), Toast::fromDetail(['message' => ['a', 'b']]));
        self::assertEquals(new Toast('Saved', null, title: 'Profile'), Toast::fromDetail(['message' => 'Saved', 'title' => 'Profile']));
        self::assertEquals(new Toast('Saved', null, title: 'Profile'), Toast::fromDetail(['message' => 'Saved', 'title' => ' Profile ']));
        // A title that is empty or not a text is no title.
        self::assertEquals(new Toast('Saved', null), Toast::fromDetail(['message' => 'Saved', 'title' => ' ']));
        self::assertEquals(new Toast('Saved', null), Toast::fromDetail(['message' => 'Saved', 'title' => true]));
        self::assertEquals(new Toast('Saved', null), Toast::fromDetail(['message' => 'Saved', 'title' => ['a']]));
    }

    public function testFromDetailReadsTheOptions(): void
    {
        $toast = new Toast('Saved', id: 'save', action: 'Undo', data: ['item' => 7], anchor: 'copy', viewport: 'top');

        self::assertEquals($toast, Toast::fromDetail($toast->detail()));
        // An option that is empty or not a text is no option, and data that is not an array is no data.
        self::assertEquals(new Toast('Saved'), Toast::fromDetail(['message' => 'Saved', 'id' => ' ', 'action' => ['a'], 'data' => 'x', 'anchor' => true, 'viewport' => '']));
        // The data keeps only the names with a scalar value or null.
        self::assertEquals(new Toast('Saved', data: ['item' => 7, 'note' => null]), Toast::fromDetail(['message' => 'Saved', 'data' => ['item' => 7, 'note' => null, 'user' => ['id' => 1], 0 => 'a']]));
    }

    public function testFromDetailGivesNullWhenTheControllerShowsNoToast(): void
    {
        self::assertNull(Toast::fromDetail(null));
        self::assertNull(Toast::fromDetail(''));
        self::assertNull(Toast::fromDetail(['message' => '']));
        self::assertNull(Toast::fromDetail(['type' => 'error']));
        self::assertNull(Toast::fromDetail(42));
    }

    public function testDetailIsTheInverseOfFromDetail(): void
    {
        $toast = new Toast('Saved', ToastType::Success, 8000);

        self::assertSame(['message' => 'Saved', 'type' => 'success', 'duration' => 8000], $toast->detail());
        self::assertEquals($toast, Toast::fromDetail($toast->detail()));
        self::assertSame('success "Saved"', (string) $toast);

        $titled = new Toast('Saved', ToastType::Success, title: 'Profile');

        self::assertSame(['message' => 'Saved', 'type' => 'success', 'title' => 'Profile'], $titled->detail());
        self::assertEquals($titled, Toast::fromDetail($titled->detail()));
        self::assertSame('success "Profile: Saved"', (string) $titled);

        // A toast with no type has no type in its detail.
        $plain = new Toast('Saved', null);

        self::assertSame(['message' => 'Saved'], $plain->detail());
        self::assertEquals($plain, Toast::fromDetail($plain->detail()));
        self::assertSame('"Saved"', (string) $plain);
    }
}
