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

namespace Phem\Tests\Test\Internal;

use Dom\HTMLDocument;
use Phem\Test\Internal\AnnounceTexts;
use PHPUnit\Framework\TestCase;

final class AnnounceTextsTest extends TestCase
{
    public function testTheTextsAreWhatAScreenReaderReads(): void
    {
        $document = HTMLDocument::createFromString(<<<'HTML'
            <!DOCTYPE html>
            <p data-phem-announce>12   results <span hidden>(filtered)</span><span aria-hidden="true">★</span><template>x</template></p>
            <div aria-hidden="true"><p data-phem-announce>Hidden</p></div>
            <p data-phem-announce hidden>Gone</p>
            <p data-phem-announce> </p>
            <div data-phem-announce>Cart: <span data-phem-announce>4 items</span></div>
            HTML, \LIBXML_NOERROR);

        self::assertSame(['12 results', 'Cart: 4 items'], AnnounceTexts::of($document));
    }

    public function testAddedTextsCountEachTextOnce(): void
    {
        self::assertSame(['12 results'], AnnounceTexts::added(['3 results'], ['12 results']));
        self::assertSame([], AnnounceTexts::added(['3 results'], ['3 results']));
        self::assertSame(['Saved'], AnnounceTexts::added(['Saved'], ['Saved', 'Saved']));
    }
}
