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
 * A markup literal, like a React element: a template and its variables.
 *
 * phemc compiles `<div>{{ x }}</div>` to `new Element('file.0.twig', ['x' => $x])`. The value is
 * not rendered yet: the Renderer renders it when the component returns it, or when a
 * template prints it.
 */
final class Element
{
    /**
     * @param string               $template the template name in the phemc output
     * @param array<string, mixed> $vars
     */
    public function __construct(
        public readonly string $template,
        public readonly array $vars = [],
    ) {
    }

    public function __toString(): string
    {
        throw new \LogicException(\sprintf('The markup of "%s" is not rendered yet. Return it from the component, or print it in markup.', $this->template));
    }
}
