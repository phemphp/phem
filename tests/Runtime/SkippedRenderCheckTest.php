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

use PHPUnit\Framework\TestCase;

/**
 * With `kernel.debug`, the dev-mode check reports a kept component whose render() is not pure
 * (see Phem\Rendering\SkippedRenderCheck).
 */
final class SkippedRenderCheckTest extends TestCase
{
    use FixtureApp;
    use SkipPage;

    public function testTheDevModeCheckReportsAKeptComponentThatChanged(): void
    {
        $data = $this->click('change');

        self::assertStringNotContainsString('Impure 1', $data['streams'], 'the impure component is kept: it shows old data');
        self::assertCount(1, $data['warnings'] ?? []);
        self::assertStringStartsWith('<Impure> did not render again after the event, but its HTML is not the same now.', $data['warnings'][0]);
    }

    public function testNoWarningForPureComponents(): void
    {
        $data = $this->click('bump');

        self::assertArrayNotHasKey('warnings', $data);
    }
}
