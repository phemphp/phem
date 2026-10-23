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

namespace Phem\Path;

/**
 * Converts a route value to the type of its #[Path] prop.
 *
 * A service that implements this interface is a converter (it is autoconfigured with the
 * `phem.path_converter` tag). The first converter that supports a prop converts its value.
 * The built-in converters have the priority -100, so the converters of an application go first.
 */
interface PathValueConverter
{
    public function supports(PathProp $prop): bool;

    /**
     * @param mixed $value the route value: a string, a default of the route, or criteria (field => value)
     *
     * @throws \UnexpectedValueException when the value does not match the type
     */
    public function convert(PathProp $prop, mixed $value): mixed;
}
