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

/**
 * Converts the value of a prop to the type of its parameter or property, before PropBinder
 * gives it to the component.
 */
interface PropConverter
{
    /**
     * The converted value, or the value as it is when the type does not take a converted value.
     *
     * @throws PropError when the value cannot become a value of the type
     */
    public function convert(?\ReflectionType $type, mixed $value, string $component, string $prop): mixed;
}
