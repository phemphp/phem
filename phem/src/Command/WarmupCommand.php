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

namespace Phem\Command;

use Phem\Twig\TemplateWarmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('phem:warmup', 'Compiles the phemc templates into the Twig cache (phemc build runs it)')]
final class WarmupCommand
{
    public function __construct(
        private readonly TemplateWarmer $warmer,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $start = hrtime(true);
        $n = $this->warmer->compile();
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln(\sprintf('phem: compiled %d template(s) into the Twig cache in %d ms', $n, (hrtime(true) - $start) / 1e6));

        return Command::SUCCESS;
    }
}
