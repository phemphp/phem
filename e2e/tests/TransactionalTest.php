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

namespace App\Tests;

use function Playwright\Testing\expect;

/**
 * A #[Transactional] handler that throws (see TransactionalPage.phem): the transaction rolls
 * back, the other browsers get no push, and the page keeps its state.
 */
final class TransactionalTest extends E2eTestCase
{
    public function testFailingHandlerRollsBackAndDropsThePush(): void
    {
        $room = 'room-'.bin2hex(random_bytes(4));
        $alice = $this->visit('/transactional/'.$room);
        self::assertResponseIsSuccessful();
        $bob = $this->visitInNewBrowser('/transactional/'.$room);

        // Bob gets the pushes only when his EventSource is open.
        $this->waitForStream($bob);

        $alice->evaluate("() => { window.failures = []; document.addEventListener('phem:error', (event) => window.failures.push(event.detail.status)) }");

        // The handler saves the note and pushes it, then it throws.
        $alice->getByLabel('Note')->fill('fail');
        $alice->locator('#save')->click();
        $alice->waitForFunction('() => window.failures.length === 1');

        self::assertSame([500], $alice->evaluate('window.failures'));
        expect($alice->locator('.phem-error'))->toHaveExactText('Something went wrong. Please try again.');

        // No morph: the component keeps its state.
        expect($alice->locator('#saves'))->toHaveExactText('0');
        expect($alice->locator('#notes li'))->toHaveCount(0);

        // The next event works. The hub keeps the order of the pushes: if the push of the
        // failed event went out, Bob would get it before this one.
        $alice->getByLabel('Note')->fill('ok');
        $alice->locator('#save')->click();
        expect($alice->locator('#saves'))->toHaveExactText('1');
        expect($alice->locator('#notes li'))->toHaveExactText('ok');

        expect($bob->locator('#notes li'))->toHaveExactText('ok');
        expect($bob->locator('#notes li'))->toHaveCount(1);

        // The database has only the note of the event that did not fail.
        $alice->reload();
        expect($alice->locator('#notes li'))->toHaveCount(1);
        expect($alice->locator('#notes li'))->toHaveExactText('ok');
    }
}
