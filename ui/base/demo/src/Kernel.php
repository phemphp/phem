<?php

declare(strict_types=1);

namespace App;

use Phem\PhemBundle;
use Phem\Ui\Base\PhemUiBaseBundle;
use Playwright\Symfony\PlaywrightSymfonyBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle;

/** The demo app: `make demo` builds its production image (etc/Dockerfile) and serves the gallery. */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new SymfonycastsTailwindBundle();
        yield new PhemBundle();
        yield new PhemUiBaseBundle();

        // The browser tests (`make test-e2e`): Playwright PHP sends the requests of the browser to
        // this kernel, in the PHPUnit process, so the tests need no web server.
        if ('test' === $this->environment) {
            yield new PlaywrightSymfonyBundle();
        }
    }
}
