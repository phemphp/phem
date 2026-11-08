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

namespace Phem\Attribute;

/**
 * Maps the form fields of an event to an object, and gives it to a parameter of the handler. The
 * Serializer sets the properties from the fields, and the Validator checks the #[Assert]
 * constraints of the object:
 *
 *     final class ClosedDates
 *     {
 *         #[Assert\NotNull(message: 'Choose the first day.')]
 *         public ?\DateTimeImmutable $from = null;
 *         public ?\DateTimeImmutable $to = null;
 *     }
 *
 *     public function addClosed(#[MapForm] ClosedDates $dates): void
 *
 * When the object is not valid, the handler does not run. The errors go to the Validation
 * property of the component (see Phem\Validation), or to an error toast when it has none. A field
 * whose value does not fit the type of its property gets the error "This value is not valid.".
 *
 * The map and the validation run before the transaction of a #[Transactional] handler: a form
 * with errors opens no transaction.
 *
 * Map to a class for the form (a DTO), not to an entity: the Serializer sets each writable
 * property from the event, and the browser can send any field, not only the fields of the form.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapForm
{
    /**
     * @param string|null          $name                 the prefix of the fields: `dates` maps the fields `dates[from]` and `dates[to]`. Null maps all the fields
     * @param list<string>         $validationGroups     the groups of the Validator. No group is the default group
     * @param array<string, mixed> $serializationContext the context of the Serializer
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $validationGroups = [],
        public readonly array $serializationContext = [],
    ) {
    }
}
