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

namespace Phem\Test\Internal;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text as TextNode;

/**
 * The texts of the `phem:announce` elements of a page, as the browser compares them before and
 * after the morph of an event of the user (assets/dist/phem_announce_changes.js): the browser
 * announces each text that no such element showed before.
 *
 * The text is the text that a screen reader reads: with no `hidden` or `aria-hidden="true"`
 * content, and with no `<template>`, `<script>` or `<style>`. An element in another
 * `phem:announce` element is part of the text of the outer element.
 *
 * @internal
 */
final class AnnounceTexts
{
    private const ANNOUNCE = '[data-phem-announce]';

    private const HIDDEN = '[hidden], [aria-hidden="true"]';

    private const SILENT_TAGS = ['template', 'script', 'style'];

    /**
     * The texts of the `data-phem-announce` elements of the page, in their order, with no empty
     * text.
     *
     * @return list<string>
     */
    public static function of(HTMLDocument $document): array
    {
        $texts = [];
        foreach ($document->querySelectorAll(self::ANNOUNCE) as $element) {
            if (null !== $element->parentElement?->closest(self::ANNOUNCE) || null !== $element->closest(self::HIDDEN)) {
                continue;
            }

            $text = Text::normalize(self::spokenText($element));
            if ('' !== $text) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * The texts of after that were not in before. A text that shows two times is new when it
     * showed only one time before.
     *
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return list<string>
     */
    public static function added(array $before, array $after): array
    {
        $added = [];
        foreach ($after as $text) {
            $index = array_search($text, $before, true);
            if (false === $index) {
                $added[] = $text;

                continue;
            }

            unset($before[$index]);
        }

        return $added;
    }

    /** The text of the element with no hidden content. */
    private static function spokenText(Element $element): string
    {
        $text = '';
        foreach ($element->childNodes as $node) {
            if ($node instanceof TextNode) {
                $text .= $node->data;
            } elseif ($node instanceof Element && !$node->matches(self::HIDDEN) && !\in_array($node->localName, self::SILENT_TAGS, true)) {
                $text .= self::spokenText($node);
            }
        }

        return $text;
    }
}
