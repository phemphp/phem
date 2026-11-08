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

use Phem\Attribute\Validated;
use Phem\Event;
use Phem\State\StateValidator;

/**
 * Validates the component of a #[Validated] handler (a method, or a closure of `render()`), and
 * stops the event when the component has errors: the handler does not run (see
 * Phem\Attribute\Validated). The other handlers run with no validation. A submit of another
 * handler removes the errors that the handlers added (see Phem\Validation::withError()): the
 * handler finds them again.
 */
final class ValidationMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly StateValidator $validator = new StateValidator(),
        private readonly HandlerAttributes $attributes = new HandlerAttributes(),
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $validated = $this->attributes->of($handler, Validated::class)[0] ?? null;
        if (null === $validated) {
            $component = 'submit' === $event->type ? new \ReflectionFunction($handler)->getClosureThis() : null;
            if (null !== $component) {
                $this->validator->submitted($component);
            }

            return $next();
        }

        $component = new \ReflectionFunction($handler)->getClosureThis();
        if (null === $component) {
            throw new \LogicException('A #[Validated] handler must be a method or a closure of a class component: a static function has no component to validate.');
        }

        if (!$this->validator->submit($component, $validated->groups)) {
            return null;
        }

        return $next();
    }
}
