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

namespace Phem\EventListener;

use Phem\Manifest\SourceMap;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Error\Error as TwigError;
use Twig\Source;

/**
 * Points errors in compiled templates to the `.phem` source: `src/Ui/HomePage.phem` at line 12.
 * The Symfony error page and the console then show the `.phem` file and line.
 */
final class SourceMapListener
{
    public function __construct(private readonly SourceMap $sourceMap)
    {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->map($event->getThrowable());
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->map($event->getError());
    }

    public function map(\Throwable $error): void
    {
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            $source = $e instanceof TwigError ? $e->getSourceContext() : null;
            $location = null !== $source ? $this->sourceMap->location($source->getName(), $e->getTemplateLine()) : null;
            if (null !== $location) {
                $e->setSourceContext(new Source('', $location['file'], $location['path']));
                $e->setTemplateLine($location['line']);
            }
        }
    }
}
