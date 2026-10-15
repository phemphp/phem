<?php

declare(strict_types=1);

namespace Rephp\Command;

use Rephp\Twig\TemplateWarmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('rephp:warmup', 'Compiles the rhpc templates into the Twig cache (rhpc build runs it)')]
final class WarmupCommand
{
    public function __construct(
        private readonly TemplateWarmer $warmer
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $start = hrtime(true);
        $n = $this->warmer->compile();
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errorOutput->writeln(\sprintf('rephp: compiled %d template(s) into the Twig cache in %d ms', $n, (hrtime(true) - $start) / 1e6));

        return Command::SUCCESS;
    }
}
