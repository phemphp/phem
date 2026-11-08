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

namespace Phem\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/** The logger of the fixture app: it keeps the messages, with their context values in place. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> "level: message" */
    public array $logs = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $values = [];
        foreach ($context as $key => $value) {
            $values['{'.$key.'}'] = \is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value);
        }

        $this->logs[] = \sprintf('%s: %s', $level, strtr((string) $message, $values));
    }
}
