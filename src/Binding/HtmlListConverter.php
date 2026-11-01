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

namespace Phem\Binding;

use Phem\HtmlList;

/**
 * A list of markup is an array for an `array` prop (see HtmlList::wantsArray()).
 */
final class HtmlListConverter implements PropConverter
{
    public function convert(?\ReflectionType $type, mixed $value, string $component, string $prop): mixed
    {
        return $value instanceof HtmlList && HtmlList::wantsArray($type) ? $value->toArray() : $value;
    }
}
