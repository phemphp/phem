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

use Phem\Argument\CallableArguments;
use Phem\Manifest\ComponentDefinition;
use Phem\Manifest\ComponentKind;
use Phem\Manifest\Manifest;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders a function page or an error page: a call with the arguments of a Symfony controller
 * (see CallableArguments). A page is not a tag, so it has no props: its parameters get the route
 * values (the props of its fiber, converted by Phem\Path\PathResolver), the Request, the
 * services, and the other controller arguments.
 *
 * In an event, the page renders again with the request of the event (`POST`, the same route
 * values).
 */
final class FunctionPageRenderer implements ComponentRenderer
{
    /** @var array<string, int>|null */
    private ?array $pages = null;

    public function __construct(
        private readonly Manifest $manifest,
        private readonly CallableArguments $arguments = new CallableArguments(),
        private readonly ?RequestStack $requests = null,
    ) {
    }

    public function supports(ComponentDefinition $definition): bool
    {
        $this->pages ??= array_flip([...$this->manifest->pages(), ...$this->manifest->errorPages()]);

        return (ComponentKind::FunctionComponent === $definition->kind || ComponentKind::TemplateComponent === $definition->kind)
            && isset($this->pages[$definition->fqcn]);
    }

    public function render(Fiber $fiber, array $slots): mixed
    {
        $function = $fiber->fqcn;
        if (!\is_callable($function)) {
            throw new \LogicException(\sprintf('The function page "%s" is not loaded. Run `phemc build` again.', $function));
        }

        return $function(...$this->arguments->of($function, $this->requests?->getCurrentRequest(), null, $fiber->props));
    }
}
