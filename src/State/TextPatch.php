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

namespace Phem\State;

/**
 * The changes from one text to a new text, in the parts between their commas: a list of splices
 * `[index, count, ...parts]`. A splice removes `count` parts of the old text at `index`, and puts
 * the new parts there. The splices are in the order of their indexes, and they do not overlap.
 *
 * An event response sends a patch of an entry of the state, and not all its text, when the patch
 * is shorter (see StateJson::changes()): the shape is a long list, and an event changes only some
 * of its hashes; a large #[State] value (a list of items) often changes in one item only. The
 * browser applies the patch to the JSON text of the entry with no JSON encoder, so its text is
 * the text that the server signed.
 */
final class TextPatch
{
    private const SEPARATOR = ',';

    /** @return list<list<int|string>> */
    public static function diff(string $old, string $new): array
    {
        $a = explode(self::SEPARATOR, $old);
        $b = explode(self::SEPARATOR, $new);
        $n = \count($a);
        $m = \count($b);

        if ($n === $m) {
            // The same number of parts (in the shape: the same fibers, other hashes): a splice for
            // each run of parts that changed.
            $patch = [];
            for ($i = 0; $i < $n; ++$i) {
                if ($a[$i] === $b[$i]) {
                    continue;
                }

                for ($start = $i; $i < $n && $a[$i] !== $b[$i]; ++$i) {
                }

                $patch[] = [$start, $i - $start, ...\array_slice($b, $start, $i - $start)];
            }

            return $patch;
        }

        // Another number of parts (a fiber more or less): one splice between the common start and
        // the common end.
        $prefix = 0;
        while ($prefix < min($n, $m) && $a[$prefix] === $b[$prefix]) {
            ++$prefix;
        }

        $suffix = 0;
        while ($suffix < min($n, $m) - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            ++$suffix;
        }

        return [[$prefix, $n - $prefix - $suffix, ...\array_slice($b, $prefix, $m - $prefix - $suffix)]];
    }

    /**
     * The text after a patch of diff(), as the browser makes it. Null for a patch with another
     * form.
     *
     * @param array<array-key, mixed> $patch
     */
    public static function apply(string $text, array $patch): ?string
    {
        $parts = explode(self::SEPARATOR, $text);
        $end = 0;

        foreach ($patch as $splice) {
            if (!\is_array($splice) || !array_is_list($splice) || !\is_int($splice[0] ?? null) || !\is_int($splice[1] ?? null)
                || $splice[0] < $end || $splice[1] < 0 || $splice[0] + $splice[1] > \count($parts)
                || \count(array_filter(\array_slice($splice, 2), \is_string(...))) !== \count($splice) - 2) {
                return null;
            }

            $end = $splice[0] + $splice[1];
        }

        // From the last splice: the indexes of the splices before it stay the same.
        foreach (array_reverse($patch) as $splice) {
            array_splice($parts, $splice[0], $splice[1], \array_slice($splice, 2));
        }

        return implode(self::SEPARATOR, $parts);
    }
}
