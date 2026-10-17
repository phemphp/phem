<?php

declare(strict_types=1);

namespace Mamu\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AsCommand('mamu:render', 'Prints the HTML of a page (no server)')]
final class RenderCommand
{
    public function __construct(
        private readonly HttpKernelInterface $httpKernel,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('The URL path of the page')] string $path = '/',
    ): int {
        // Errors are not caught, so the console shows them (with the .phpm location).
        $response = $this->httpKernel->handle(Request::create($path), HttpKernelInterface::MAIN_REQUEST, false);
        $output->write((string) $response->getContent(), false, OutputInterface::OUTPUT_RAW);

        return $response->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
