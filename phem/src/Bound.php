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

namespace Phem;

/**
 * A #[State] property of a parent component, passed to a child component with `phem:bind`:
 * `<TextField phem:bind={{ query }} />`. The child declares a prop of this type, and binds a
 * form field to it: `<input phem:bind={{ value }} />`. The field shows the value of the parent
 * property, and the next event sets the parent property (see BoundValues). The child keeps no
 * copy of the value.
 */
final readonly class Bound
{
    /**
     * @param string|list<mixed> $target the property, or the property and the keys of a path
     * @param mixed              $value  the current value
     * @param object|null        $owner  the component of the property
     */
    public function __construct(
        public string|array $target,
        public mixed $value,
        public ?object $owner = null,
    ) {
    }
}
