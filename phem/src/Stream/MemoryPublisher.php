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

namespace Phem\Stream;

/**
 * Keeps the updates in memory, for the tests (`framework.test: true`): a ComponentTester of a
 * page gets the updates of its topics with receive() (see Phem\Test\ComponentTester).
 */
final class MemoryPublisher implements StreamPublisher
{
    /** @var list<array{topics: list<string>, data: string, private: bool}> */
    private array $updates = [];

    public function publish(array $topics, string $data, bool $private): void
    {
        $this->updates[] = ['topics' => $topics, 'data' => $data, 'private' => $private];
    }

    /**
     * The updates in their order, from the first one.
     *
     * @return list<array{topics: list<string>, data: string, private: bool}>
     */
    public function updates(): array
    {
        return $this->updates;
    }
}
