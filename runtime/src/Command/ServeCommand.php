<?php

declare(strict_types=1);

namespace Rephp\Command;

use Rephp\Server\HttpServer;
use Rephp\Server\KernelHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs the application on the Io\Poll HTTP server: one long-running process. The kernel
 * handles each request and resets the services (`kernel.reset`) between requests.
 */
#[AsCommand('rephp:serve', 'Runs the application on the Io\Poll HTTP server')]
final class ServeCommand
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly string $projectDir,
        private readonly bool $debug,
    ) {
    }

    /**
     * @param list<string> $mount
     */
    public function __invoke(
        OutputInterface $output,
        #[Option('The address to listen on')] string $host = '127.0.0.1',
        #[Option('The port to listen on')] int $port = 8080,
        #[Option('One more static directory: /url/=dir (relative to the project)')] array $mount = [],
    ): int {
        $log = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $mounts = [];
        foreach ($mount as $entry) {
            [$url, $dir] = explode('=', $entry, 2) + [1 => ''];
            $mounts[$url] = str_starts_with($dir, '/') ? $dir : $this->projectDir.'/'.$dir;
        }
        if (is_dir($this->projectDir.'/public')) {
            $mounts['/'] = $this->projectDir.'/public';
        }

        $server = new HttpServer(
            new KernelHandler($this->kernel, $host, $port),
            $host,
            $port,
            $mounts,
            static function (string $line) use ($log): void {
                $log->writeln($line, OutputInterface::OUTPUT_RAW);
            },
            debug: $this->debug,
        );
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGINT, $server->stop(...));
            pcntl_signal(\SIGTERM, $server->stop(...));
        }
        $log->writeln(\sprintf('rephp: listening on http://%s:%d (Io\Poll backend: %s)', $host, $server->getPort(), (new \Io\Poll\Context())->getBackend()->name));
        $server->run();

        return Command::SUCCESS;
    }
}
