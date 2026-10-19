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

namespace Phem\Rendering;

use Phem\Handler\HandlerFingerprint;
use Phem\LazyHtml;
use Twig\Markup;

/**
 * Compares what a parent gives a fiber in two passes, as React compares props with `Object.is`.
 * The caller context counts only for a fiber with slots: its slots render with it. The values:
 * the same scalars and arrays, the same objects (identity), a closure with the same fingerprint
 * and the same `$this`, and markup with the same HTML. Lazy markup (a slot, markup that renders a
 * component) is the same only when it is the same object.
 *
 * An object that a handler changes in place (an entity) is the same object: the fibers that get
 * it as a prop do not render again.
 *
 * @internal
 */
final class InputsComparator
{
    public function __construct(private readonly HandlerFingerprint $fingerprints = new HandlerFingerprint())
    {
    }

    public function same(FiberInputs $a, FiberInputs $b): bool
    {
        // The caller context is for the slots only.
        return $a->slots === $b->slots && $this->equal($a->props, $b->props) && (null === $a->slots || $this->equal($a->caller, $b->caller));
    }

    private function equal(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b) || array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!$this->equal($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }
        if ($a instanceof \Closure && $b instanceof \Closure) {
            return new \ReflectionFunction($a)->getClosureThis() === new \ReflectionFunction($b)->getClosureThis()
                && $this->fingerprints->of($a) === $this->fingerprints->of($b);
        }
        if ($a instanceof Markup && $b instanceof Markup && !$a instanceof LazyHtml && !$b instanceof LazyHtml) {
            return $a::class === $b::class && (string) $a === (string) $b;
        }

        return false;
    }
}
