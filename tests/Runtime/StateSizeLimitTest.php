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

use Phem\Rendering\Fiber;
use Phem\Rendering\Tree;
use Phem\State\StateJson;
use Phem\State\StateSizeLimit;
use Phem\Tests\Fixtures\Many;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * A state that the browser cannot send back in an event (see StateSizeLimit).
 */
final class StateSizeLimitTest extends TestCase
{
    use FixtureApp;

    private const FIBER = 'phem0123456789ab';

    public function testTheBodySizeIsTheSizeOfTheStateAsAJsonString(): void
    {
        $json = StateJson::encode(['_tree' => 'AAAAAAAA0', self::FIBER => ['text' => "a \"b\" c\\d\ne é/", 'n' => 1]]);

        // As JSON.stringify() in the browser: no escape for the slashes and the Unicode characters.
        self::assertSame(\strlen(json_encode($json, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)) - 2, StateSizeLimit::bodySize($json));
    }

    public function testAStateUnderTheLimitPasses(): void
    {
        $logger = self::logger();
        $json = self::state(100);

        new StateSizeLimit(StateSizeLimit::bodySize($json) + 1, true, $logger)->check($json, self::tree());
        new StateSizeLimit(0, true, $logger)->check(self::state(10000), self::tree());

        self::assertSame([], $logger->records, 'no limit with 0');
    }

    public function testALargerStateThrowsInDebugMode(): void
    {
        $json = self::state(1000);
        $size = StateSizeLimit::bodySize($json);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('The state of the page "App\Page\Big" takes %d bytes in the body of an event, but an event body can have a maximum of %d bytes (the phem.max_event_size option)', $size, $size));
        $this->expectExceptionMessage('The largest entry is the state of <Big>');

        new StateSizeLimit($size, true)->check($json, self::tree());
    }

    public function testALargerStateLogsAnErrorInProduction(): void
    {
        $logger = self::logger();
        $json = self::state(1000);

        new StateSizeLimit(1000, false, $logger)->check($json, self::tree());

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertStringContainsString('the browser cannot send the state back', (string) $logger->records[0][1]);
        self::assertSame(['page' => 'App\Page\Big', 'size' => StateSizeLimit::bodySize($json), 'max_event_size' => 1000], $logger->records[0][2]);
    }

    public function testAPageWithAStateTooLargeForAnEventFailsInDebugMode(): void
    {
        // About 1.7 MB in the body of an event: phem.max_event_size is 1 MiB.
        Many::$rows = 30000;

        try {
            $response = $this->request('GET', '/many-items');
        } finally {
            Many::$rows = 3;
        }

        $content = html_entity_decode((string) $response->getContent());
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('(the phem.max_event_size option)', $content);
        self::assertStringContainsString('The largest entry is the state of <ManyItemsPage>', $content);
    }

    /** A state with a list of items in the entry of the fiber. */
    private static function state(int $items): string
    {
        return StateJson::encode(['_tree' => 'AAAAAAAA0', self::FIBER => ['items' => array_map(static fn (int $i): string => 'Item '.$i, range(1, $items))]]);
    }

    private static function tree(): Tree
    {
        $tree = new Tree('App\Page\Big');
        $tree->fiber(self::FIBER, static fn (?array $restored): Fiber => new Fiber(self::FIBER, '/App\Page\Big#0', 'App\Page\Big', $restored));

        return $tree;
    }

    /** @return AbstractLogger&object{records: list<array{0: mixed, 1: string|\Stringable, 2: array<mixed>}>} */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string|\Stringable, 2: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
    }
}
