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

namespace Phem\Twig;

use Phem\Html;
use Twig\Environment;
use Twig\Runtime\EscaperRuntime;

/**
 * Makes Html (and LazyHtml) a safe class of the Twig escaper: `{{ children }}` prints the markup
 * without escaping, for all the escaping strategies.
 *
 * It decorates the configurator of the TwigBundle, which runs when the `twig` service is built.
 * MarkupResolver also calls safeHtml() on its Environment, so an Environment outside the
 * container prints Html too.
 */
final class EnvironmentConfigurator
{
    /**
     * @param object|null $inner the decorated configurator: an object with a `configure(Environment)` method
     */
    public function __construct(private readonly ?object $inner = null)
    {
    }

    public static function safeHtml(Environment $twig): void
    {
        $twig->getRuntime(EscaperRuntime::class)->addSafeClass(Html::class, ['all']);
    }

    public function configure(Environment $twig): void
    {
        if (null !== $this->inner && method_exists($this->inner, 'configure')) {
            $this->inner->configure($twig);
        }

        self::safeHtml($twig);
    }
}
