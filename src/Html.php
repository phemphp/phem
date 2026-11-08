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

namespace Phem;

/**
 * Rendered markup. Twig prints it without escaping (Phem\Twig\EnvironmentConfigurator makes it a
 * safe class of the escaper).
 * A markup literal in PHP (`$content = <AdminPanel />;`) is an Element. It becomes Html when it
 * passes to a component as a prop or a slot.
 */
class Html implements Node, \Stringable, \Countable, \JsonSerializable
{
    public function __construct(private readonly string $html)
    {
    }

    public static function empty(): self
    {
        return new self('');
    }

    public function __toString(): string
    {
        return $this->html;
    }

    public function count(): int
    {
        return mb_strlen((string) $this, 'UTF-8');
    }

    public function jsonSerialize(): mixed
    {
        return (string) $this;
    }
}
