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

namespace Phem\Dom;

use Phem\Rendering\RootTag;
use Twig\Markup;

/**
 * The tag name of a `<Dynamic>` element (see Phem\Dynamic): `phem_tag()` for the open tag and
 * `phem_end_tag()` for the end tag.
 *
 * The tag can come from data, so it is checked: an HTML element name, and not `script` or
 * `style`, whose text runs or applies with no escape.
 */
final class DynamicTag
{
    private const RAW_TEXT = ['script' => true, 'style' => true];

    /** The name of the open tag: `<{{ phem_tag(tag) }} ...>`. */
    public static function open(mixed $tag): string
    {
        if (!\is_string($tag) || 1 !== preg_match('/^[a-z][a-z0-9-]*$/i', $tag)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid tag name for <Dynamic>.', \is_string($tag) ? $tag : get_debug_type($tag)));
        }

        if (isset(self::RAW_TEXT[strtolower($tag)])) {
            throw new \InvalidArgumentException(\sprintf('<Dynamic> cannot render a <%s>: write it as static markup.', strtolower($tag)));
        }

        return $tag;
    }

    /**
     * The content and the end tag: `{{ phem_end_tag(tag, content) }}`. A void element has no
     * end tag, and its content must be empty.
     */
    public static function close(mixed $tag, mixed $content = ''): Markup
    {
        $tag = self::open($tag);
        $content = (string) $content;

        if (isset(RootTag::VOID[strtolower($tag)])) {
            if ('' !== trim($content)) {
                throw new \InvalidArgumentException(\sprintf('<%s> is a void element: <Dynamic tag="%1$s"> cannot have children.', strtolower($tag)));
            }

            return new Markup('', 'UTF-8');
        }

        return new Markup($content.'</'.$tag.'>', 'UTF-8');
    }
}
