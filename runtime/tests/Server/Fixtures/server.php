<?php

declare(strict_types=1);

// Starts an HttpServer on a free port for HttpServerTest and prints the port on stdout.

use Rephp\Server\HttpServer;
use Rephp\Server\Request;
use Rephp\Server\RequestHandler;
use Rephp\Server\Response;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$handler = new class implements RequestHandler {
    public ?HttpServer $server = null;

    public function handle(Request $request): ?Response
    {
        return match ($request->path) {
            '/hello' => Response::html('<h1>Hello</h1>'),
            '/echo' => Response::text(json_encode([
                'method' => $request->method,
                'query' => $request->query,
                'type' => $request->header('Content-Type'),
                'body' => $request->body,
            ], \JSON_THROW_ON_ERROR)),
            '/big' => Response::text(str_repeat('0123456789abcdef', 262_144)),
            '/boom' => throw new RuntimeException('Kaboom'),
            '/stop' => $this->stop(),
            default => null,
        };
    }

    private function stop(): Response
    {
        $this->server?->stop();

        return Response::text("bye\n");
    }
};

$server = new HttpServer(
    $handler,
    port: 0,
    staticMounts: ['/' => __DIR__.'/public'],
    logger: static function (string $line): void {
        fwrite(\STDERR, $line.\PHP_EOL);
    },
    maxBodyBytes: 1024,
    idleTimeout: (float) ($argv[1] ?? 15.0),
);

$handler->server = $server;

echo $server->getPort(), \PHP_EOL;

$server->run();

echo 'stopped', \PHP_EOL;
