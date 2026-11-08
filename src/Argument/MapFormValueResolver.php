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

namespace Phem\Argument;

use Phem\Attribute\MapForm;
use Phem\Event;
use Phem\Form\FormMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Gives the object of the form of the event to a #[MapForm] parameter of a handler (see
 * Phem\Attribute\MapForm). Phem\Handler\MapFormMiddleware maps it first: the handler gets the
 * same object.
 */
final class MapFormValueResolver implements ValueResolverInterface
{
    public function __construct(private readonly FormMapper $forms = new FormMapper())
    {
    }

    /** @return iterable<object> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $attribute = $argument->getAttributesOfType(MapForm::class)[0] ?? null;
        if (null === $attribute) {
            return [];
        }

        $event = $request->attributes->get(CallableArguments::EVENT);
        if (!$event instanceof Event) {
            throw new \LogicException(\sprintf('The #[MapForm] parameter $%s maps the form of an event: use it in an event handler.', $argument->getName()));
        }

        return [$this->forms->map($event, $argument->getName(), (string) $argument->getType(), $attribute)];
    }
}
