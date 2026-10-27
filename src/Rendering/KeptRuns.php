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

namespace Phem\Rendering;

use Phem\State\TreeShape;

/**
 * The placeholders of the kept fibers (see Fiber::wrapped()) in the HTML of an event. A run of
 * placeholders with nothing between them, of fibers that were the next children of one parent in
 * the shape before the event, becomes one placeholder `<first>..<last>`: a list of 200 kept rows
 * sends one placeholder, not 200.
 *
 * The browser finds the fibers of a run in the shape that it has, the shape before the event (see
 * TreeShape): `first`, then the next children of its parent up to `last`.
 */
final class KeptRuns
{
    /** Two placeholders or more with nothing between them. */
    private const RUN = '#(?:<template data-phem-keep="phem[0-9a-f]{12}"></template>){2,}#';

    private const PLACEHOLDER = '#<template data-phem-keep="(phem[0-9a-f]{12})\.\.(phem[0-9a-f]{12})"></template>#';

    private const ID = '#phem[0-9a-f]{12}#';

    public function collapse(string $html, TreeShape $before): string
    {
        return preg_replace_callback(self::RUN, static function (array $m) use ($before): string {
            preg_match_all(self::ID, $m[0], $ids);

            $runs = [];
            foreach ($ids[0] as $i => $id) {
                if (0 < $i && $before->nextSiblingOf($ids[0][$i - 1]) === $id) {
                    $runs[\count($runs) - 1][1] = $id;
                } else {
                    $runs[] = [$id, $id];
                }
            }

            $html = '';
            foreach ($runs as [$first, $last]) {
                $html .= '<template data-phem-keep="'.($first === $last ? $first : $first.'..'.$last).'"></template>';
            }

            return $html;
        }, $html) ?? $html;
    }

    /**
     * The HTML with a placeholder for each fiber of each run, as the browser finds them. Null
     * when the shape does not have a run.
     */
    public function expand(string $html, TreeShape $before): ?string
    {
        $missing = false;
        $html = preg_replace_callback(self::PLACEHOLDER, static function (array $m) use ($before, &$missing): string {
            $html = '';
            for ($id = $m[1]; null !== $id; $id = $id === $m[2] ? null : $before->nextSiblingOf($id)) {
                $html .= '<template data-phem-keep="'.$id.'"></template>';
            }

            $missing = $missing || !str_ends_with($html, '"'.$m[2].'"></template>');

            return $html;
        }, $html);

        return $missing ? null : $html;
    }
}
