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

use Symfony\Component\HttpFoundation\Request;

/**
 * The fields of a Symfony form that the user touched, by their full name (`signup[email]`). An
 * event of a form gives them to the copy of its request (see Phem\Http\EventRequest): a submit
 * touches all the fields, and another event touches the fields that the browser sends.
 *
 * A name ends with no `[]`: `signup[tags][]` is `signup[tags]`. A touched field touches its
 * parents and its children: `signup[birthday][year]` touches `signup[birthday]`, and
 * `signup[tags]` touches `signup[tags][0]`.
 */
final readonly class TouchedFields
{
    /** The attribute of the request of an event of a form that keeps its touched fields. */
    public const string REQUEST_ATTRIBUTE = '_phem_touched';

    /** The HTML attribute that marks a touched field (see TouchedFieldsExtension). */
    public const string MARK = 'data-phem-touched';

    /**
     * @param list<string>|null $names null for all the fields
     */
    private function __construct(
        private ?array $names,
    ) {
    }

    public static function all(): self
    {
        return new self(null);
    }

    /**
     * The names that the browser sends. A value that is not a string is left out.
     */
    public static function of(mixed $names): self
    {
        // The names are the values: a numeric key would become an int.
        $touched = [];
        foreach (\is_array($names) ? $names : [] as $name) {
            if (\is_string($name) && '' !== $name = self::normalize($name)) {
                $touched[$name] = $name;
            }
        }

        return new self(array_values($touched));
    }

    /**
     * Touches all the fields of the forms that a handler submits with this request, as a submit
     * does: for example a handler that saves the form on the click of a button.
     *
     *     $this->form->handleRequest(TouchedFields::touchAll($request));
     */
    public static function touchAll(Request $request): Request
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, self::all());

        return $request;
    }

    /** Whether the user touched all the fields: the form was submitted. */
    public function isAll(): bool
    {
        return null === $this->names;
    }

    /** Whether the user touched a field, one of its parents, or one of its children. */
    public function touches(string $name): bool
    {
        if (null === $this->names) {
            return true;
        }

        $name = self::normalize($name);

        foreach ($this->names as $touched) {
            if ($touched === $name || str_starts_with($touched, $name.'[') || str_starts_with($name, $touched.'[')) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $name): string
    {
        return str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
    }
}
