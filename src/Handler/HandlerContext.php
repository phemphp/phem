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

namespace Phem\Handler;

use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\Request;

/**
 * The values of an event handler for a Closure or an Expression in an attribute, as Symfony
 * gives them for a controller: the controller is the component, and the arguments are the
 * arguments of the call in the markup (see HandlerCallMiddleware).
 *
 * A Closure gets `($arguments, $request, $component)`. An Expression gets `args`, `request`
 * and `this`.
 */
final class HandlerContext
{
    /**
     * @param array<string, mixed> $arguments the arguments of the call, by parameter name
     */
    public function __construct(
        public readonly array $arguments,
        public readonly ?Request $request,
        public readonly ?object $component,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public static function of(\Closure $handler, array $arguments, ?Request $request): self
    {
        return new self($arguments, $request, new \ReflectionFunction($handler)->getClosureThis());
    }

    /**
     * @param string $what the value, for the error: `a #[RateLimit] key`
     *
     * @throws \LogicException for an Expression with no ExpressionLanguage
     */
    public function evaluate(Expression|\Closure $value, ?ExpressionLanguage $language, string $what): mixed
    {
        if ($value instanceof \Closure) {
            return $value($this->arguments, $this->request, $this->component);
        }

        $language ??= throw new \LogicException(\sprintf('Cannot evaluate the Expression of %s since no ExpressionLanguage service was configured.', $what));

        return $language->evaluate($value, ['args' => $this->arguments, 'request' => $this->request, 'this' => $this->component]);
    }
}
