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
 * Validates the component before an event handler (a submit), with the live validation of its
 * Validation property (see Phem\Validation). It touches all the fields, so each error shows. When
 * there are errors, the handler does not run, and the component renders with its errors.
 *
 * It marks a handler method, or a closure of `render()`: `#[Validated] fn () => $this->save()`.
 *
 * The groups are the validation groups of Symfony. The next live validations of the component use
 * them too, until a new Validation. Without groups, the default group validates (or the group
 * sequence of the class).
 *
 *     #[Validated(groups: ['draft'])]
 *     public function saveDraft(): void
 *
 * The validation runs before the transaction of a #[Transactional] handler: a form with errors
 * opens no transaction.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final class Validated
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly array $groups = [],
    ) {
    }
}
