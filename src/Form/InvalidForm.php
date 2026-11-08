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

namespace Phem\Form;

/**
 * The form of an event does not give a valid object (see Phem\Attribute\MapForm). The handler
 * does not run, and Phem\Handler\MapFormMiddleware shows the errors.
 *
 * @internal
 */
final class InvalidForm extends \RuntimeException
{
    /**
     * @param non-empty-array<string, string> $errors the first error of each field
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(\sprintf('The form is not valid: %s', implode(' ', array_map(static fn (string $f, string $e): string => \sprintf('"%s": %s', $f, $e), array_keys($errors), $errors))));
    }
}
