<?php

declare(strict_types=1);

namespace App;

use Phem\PhemBundle;
use Phem\Ui\Base\PhemUiBaseBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle;

/** The demo app: `make demo` builds the .phem files with phemc and serves the gallery. */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new StimulusBundle(),
            new SymfonycastsTailwindBundle(),
            new PhemBundle(),
            new PhemUiBaseBundle(),
        ];
    }
}
