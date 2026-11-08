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

use Phem\Attribute\MapForm;
use Phem\Event;
use Phem\Form\FormMapper;
use Phem\Form\InvalidForm;
use Phem\State\StateValidator;
use Phem\ToastType;

/**
 * Maps the form of the event for each #[MapForm] parameter of a handler, before the handler and
 * its transaction (see Phem\Attribute\MapForm). When an object is not valid, the handler does not
 * run: the errors go to the Validation property of the component, or the first error with a
 * message goes to an error toast when the component has none. A valid form removes the errors of the last map.
 */
final class MapFormMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly FormMapper $forms = new FormMapper(),
        private readonly StateValidator $validator = new StateValidator(),
        private readonly HandlerAttributes $attributes = new HandlerAttributes(),
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $parameters = $this->attributes->ofParameters($handler, MapForm::class);
        if ([] === $parameters) {
            return $next();
        }

        $component = new \ReflectionFunction($handler)->getClosureThis();

        try {
            foreach ($parameters as $name => [$attribute, $type]) {
                $this->forms->map($event, $name, $type, $attribute);
            }
        } catch (InvalidForm $e) {
            // The first error with a message: a constraint can have an empty message.
            $message = array_find($e->errors, static fn (string $error): bool => '' !== trim($error));

            if (!$this->validator->mapped($component, $e->errors) && null !== $message) {
                $event->toast($message, ToastType::Error);
            }

            return null;
        }

        $this->validator->mapped($component, []);

        return $next();
    }
}
