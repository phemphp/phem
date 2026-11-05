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

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AsCommand('phem:render', 'Prints the HTML of a page (no server)')]
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
        // Errors are not caught, so the console shows them (with the .phem location).
        $response = $this->httpKernel->handle(Request::create($path), HttpKernelInterface::MAIN_REQUEST, false);
        $output->write((string) $response->getContent(), false, OutputInterface::OUTPUT_RAW);

        return $response->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
