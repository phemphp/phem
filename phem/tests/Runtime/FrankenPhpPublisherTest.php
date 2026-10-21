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

use Phem\Stream\FrankenPhpPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class FrankenPhpPublisherTest extends TestCase
{
    public function testWithNoFrankenPhpTheLoggerGetsTheErrorAndTheRequestGoesOn(): void
    {
        self::assertFalse(\function_exists('mercure_publish'), 'the tests run in the CLI');
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->errors[] = (string) $message;
            }
        };

        new FrankenPhpPublisher($logger)->publish(['phem:*'], '<turbo-stream></turbo-stream>', false);

        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('mercure_publish() does not exist', $logger->errors[0]);
    }
}
