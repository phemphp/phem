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

use Phem\Tests\Fixtures\Setting;
use PHPUnit\Framework\TestCase;

/**
 * The slots belong to the owner: a component with slots renders again when its owner renders
 * again, also when the handler changed an object in place (see Phem\Rendering\UpdateScope).
 */
final class SlotOwnerTest extends TestCase
{
    use FixtureApp;

    protected function setUp(): void
    {
        Setting::$current = null;
    }

    public function testTheSlotsShowTheObjectThatTheHandlerChangedInPlace(): void
    {
        $html = (string) $this->request('GET', '/skip/slots')->getContent();
        self::assertSame(1, preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m));
        self::assertSame(1, preg_match('/<button role="radio" value="0" aria-checked="false"[^>]*data-phem-on-click="([^"]+)"/', $html, $h));

        $response = $this->event('/skip/slots', ['state' => html_entity_decode($m[1]), 'signature' => $m[2], 'handler' => $h[1], 'event' => ['type' => 'click', 'value' => '0']]);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, Setting::current()->days);
        self::assertStringContainsString('<button role="radio" value="0" aria-checked="true"', $data['streams'], 'the two panels render again with the slots of the page');
        self::assertStringContainsString('<button role="radio" value="1" aria-checked="false"', $data['streams']);
        self::assertArrayNotHasKey('warnings', $data);
    }
}
