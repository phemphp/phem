<?php

declare(strict_types=1);

namespace App\Ui;

/**
 * The index of a page ("On this page"): the headings with a `data-contents` attribute in the
 * rendered content of the page, in their order.
 */
final class Contents
{
    /**
     * @return list<array{id: string, title: string, level: int}>
     */
    public static function headings(string $html): array
    {
        preg_match_all('/<h([23])\b[^>]*\bid="([^"]+)"[^>]*\bdata-contents\b[^>]*>(.*?)<\/h\1>/s', $html, $matches, \PREG_SET_ORDER);

        $headings = [];
        foreach ($matches as [, $level, $id, $title]) {
            $headings[] = [
                'id' => html_entity_decode($id, \ENT_QUOTES | \ENT_HTML5),
                'title' => html_entity_decode(strip_tags($title), \ENT_QUOTES | \ENT_HTML5),
                'level' => (int) $level,
            ];
        }

        return $headings;
    }
}
