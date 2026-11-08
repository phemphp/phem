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

namespace Phem\Http;

/**
 * The form fields of an event, in their order. The browser sends [name, value] pairs, so a name
 * can have more than one value (`<select multiple>`, checkboxes). A hash of name => value also
 * works.
 */
final class FormFields
{
    /** @return list<array{string, string}> */
    public static function of(mixed $form): array
    {
        if (!\is_array($form)) {
            return [];
        }

        $fields = [];
        foreach ($form as $name => $value) {
            if (\is_int($name) && \is_array($value) && \is_string($value[0] ?? null) && \is_scalar($value[1] ?? null)) {
                $fields[] = [$value[0], (string) $value[1]];
            } elseif (\is_string($name) && \is_scalar($value)) {
                $fields[] = [$name, (string) $value];
            }
        }

        return $fields;
    }

    /**
     * The fields in nested arrays, as PHP makes them from the body of a normal POST: FormData
     * gives flat names (`scientist[name]`, `tags[]`).
     *
     * @param list<array{string, string}> $fields
     *
     * @return array<array-key, mixed>
     */
    public static function nested(array $fields): array
    {
        parse_str(implode('&', array_map(static fn (array $f): string => rawurlencode($f[0]).'='.rawurlencode($f[1]), $fields)), $nested);

        return $nested;
    }
}
