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

// The benchmark of the client/server flow: `make bench`. It runs the pages of the fixture app
// (tests/Fixtures/app) in the `nodebug` kernel, as the phem Stimulus controller sends the
// events, and prints the median server time, the bytes on the wire, the requests of each step
// and the writes to the state cache. The time has no network: a request more costs one round
// trip more in a browser.

use Phem\State\StateCache;
use Phem\Test\Internal\StreamedContent;
use Phem\Tests\Fixtures\Many;
use Phem\Tests\Fixtures\TestKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__).'/vendor/autoload.php';

const RUNS = 30;

$kernel = new TestKernel('nodebug', false);
$kernel->boot();
$pool = $kernel->getContainer()->get('test.service_container')->get(StateCache::POOL);
assert($pool instanceof CacheItemPoolInterface);

/** @return array{float, Response} the time in ms, and the response */
$handle = static function (Request $request) use ($kernel): array {
    $start = hrtime(true);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [(hrtime(true) - $start) / 1e6, $response];
};

$get = static fn (string $uri): array => $handle(Request::create($uri));

/** @return array{float, float, string} the time to the first chunk and to the last chunk in ms, and the HTML of a streamed page */
$stream = static function (string $uri) use ($kernel): array {
    $request = Request::create($uri);
    $start = hrtime(true);
    $response = $kernel->handle($request);
    $first = null;

    $html = StreamedContent::of($response, static function () use (&$first, $start): void {
        $first ??= (hrtime(true) - $start) / 1e6;
    });
    $kernel->terminate($request, $response);

    return [$first ?? 0.0, (hrtime(true) - $start) / 1e6, $html];
};

/**
 * Sends an event as the controller does: only the signature, and the state again after a 409.
 *
 * @param string|list<string> $handler
 *
 * @return array{float, Response, int, int} the time of all the requests, the last response, the requests, and the bytes of the last body (gzip above 1 KiB, as the browser)
 */
$event = static function (string $uri, array $page, string|array $handler, string $type = 'click', bool $inline = false) use ($handle): array {
    $time = 0.0;
    $requests = 0;

    foreach ($inline ? [true] : [false, true] as $withState) {
        $body = json_encode(($withState ? ['state' => $page['state']] : []) + ['signature' => $page['signature'], 'handler' => $handler, 'event' => ['type' => $type, 'value' => null, 'checked' => null, 'key' => null, 'form' => null], 'bound' => new stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PHEM_EVENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        if (strlen($body) >= 1024) {
            $body = (string) gzencode($body);
            $server['HTTP_CONTENT_ENCODING'] = 'gzip';
        }

        [$ms, $response] = $handle(Request::create($uri, 'POST', server: $server, content: $body));
        $time += $ms;
        ++$requests;

        if (409 !== $response->getStatusCode() || '{"state":"required"}' !== $response->getContent()) {
            break;
        }
    }

    return [$time, $response, $requests, strlen($body)];
};

/** @return array{state: string, signature: string, html: string, inline: bool} */
$page = static function (Response $response): array {
    $html = (string) $response->getContent();
    preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"/', $html, $m) ?: throw new RuntimeException('No state in the page.');
    $state = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

    // As the controller: a state under data-phem-state-inline-value goes with the event, and all
    // the states when the page has no such attribute.
    $inline = 1 === preg_match('/data-phem-state-inline-value="(\d+)"/', $html, $size) ? strlen($state) < (int) $size[1] : true;

    return ['state' => $state, 'signature' => html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5), 'html' => $html, 'inline' => $inline];
};

$attr = static function (string $html, string $pattern): array {
    preg_match_all($pattern, $html, $m);

    return $m[1];
};

$cached = static fn (string $signature): bool => $pool->hasItem(hash('xxh128', $signature));

$median = static function (callable $run): float {
    $times = [];
    for ($i = 0; $i < RUNS; ++$i) {
        $times[] = $run();
    }

    sort($times);

    return $times[intdiv(RUNS, 2)];
};

$rows = [];
$row = static function (string $name, float $ms, int $requests, int $request, int $response, string $cache) use (&$rows): void {
    $rows[] = [$name, sprintf('%.2f', $ms), (string) $requests, (string) $request, (string) $response, $cache];
};

// --- A page with many components ---------------------------------------------------------
foreach ([3, 200] as $count) {
    Many::$rows = $count;
    [, $response] = $get('/many');
    $p = $page($response);
    $state = strlen($p['state']);
    $row("GET /many ({$count} rows, state {$state} B)", $median(static fn () => $get('/many')[0]), 1, 0, strlen($p['html']), $cached($p['signature']) ? 'write' : '-');

    $bump = $attr($p['html'], '/id="bump" data-phem-on-click="([^"]+)"/')[0] ?? throw new RuntimeException('No bump handler.');
    foreach (['signature' => false, 'inline' => true, 'browser rule: '.($p['inline'] ? 'inline' : 'signature') => $p['inline']] as $mode => $inline) {
        $pool->clear();
        [, $response, $requests, $bytes] = $event('/many', $p, $bump, inline: $inline);
        $out = json_decode((string) $response->getContent(), true);
        $miss = static function () use ($pool, $event, $p, $bump, $inline): float {
            $pool->clear();

            return $event('/many', $p, $bump, inline: $inline)[0];
        };
        $row("click /many ({$count} rows, {$mode}, cache miss)", $median($miss), $requests, $bytes, strlen((string) $response->getContent()), $cached((string) ($out['signature'] ?? '')) ? 'write' : '-');
    }

    // A cache hit: the state of the page is in the pool.
    $get('/many');
    [, $response] = $get('/many');
    $p = $page($response);
    if ($cached($p['signature'])) {
        [, $response, $requests, $bytes] = $event('/many', $p, $bump);
        $row("click /many ({$count} rows, signature, cache hit)", $median(static fn () => $event('/many', $p, $bump)[0]), $requests, $bytes, strlen((string) $response->getContent()), 'write');
    }
}

// --- A deep page: the event renders the path to the leaf -----------------------------------
[, $response] = $get('/bench/deep');
$p = $page($response);
$leaf = $attr($p['html'], '/id="leaf" data-phem-on-click="([^"]+)"/')[0] ?? throw new RuntimeException('No leaf handler.');
$row('GET /bench/deep (11 levels)', $median(static fn () => $get('/bench/deep')[0]), 1, 0, strlen($p['html']), $cached($p['signature']) ? 'write' : '-');
[, $response, $requests, $bytes] = $event('/bench/deep', $p, $leaf, inline: true);
$row('click /bench/deep (leaf, inline)', $median(static fn () => $event('/bench/deep', $p, $leaf, inline: true)[0]), $requests, $bytes, strlen((string) $response->getContent()), '-');

// --- A dashboard with 4 <Suspense> ---------------------------------------------------------
[, $response] = $get('/bench/dashboard');
$p = $page($response);
$loads = $attr($p['html'], '/data-phem-load="([^"]+)"/');
$row('GET /bench/dashboard (4 boundaries)', $median(static fn () => $get('/bench/dashboard')[0]), 1, 0, strlen($p['html']), $cached($p['signature']) ? 'write' : '-');

// One load event after the other, as the queue sends them: each one has the state of the last one.
$sequential = static function () use ($event, $p, $loads): array {
    $state = $p;
    $time = 0.0;
    $requests = 0;
    $bytes = 0;
    foreach ($loads as $load) {
        [$ms, $response, $n, $sent] = $event('/bench/dashboard', $state, $load, 'load', true);
        $time += $ms;
        $requests += $n;
        $bytes += $sent + strlen((string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $state = ['state' => Phem\State\StateJson::apply($state['state'], $data['changes']), 'signature' => $data['signature']];
    }

    return [$time, $requests, $bytes];
};
[, $requests, $bytes] = $sequential();
$row('load /bench/dashboard (one event per boundary)', $median(static fn () => $sequential()[0]), $requests, 0, $bytes, '-');

[, $response, $requests, $bytes] = $event('/bench/dashboard', $p, $loads, 'load', true);
if (200 === $response->getStatusCode()) {
    $row('load /bench/dashboard (one batched event)', $median(static fn () => $event('/bench/dashboard', $p, $loads, 'load', true)[0]), $requests, $bytes, strlen((string) $response->getContent()), '-');
} else {
    $row('load /bench/dashboard (one batched event)', 0.0, 0, 0, 0, 'n/a ('.$response->getStatusCode().')');
}

// --- The same dashboard, streamed in the response of the page -----------------------------
[, , $html] = $stream('/bench/stream');
$row('GET /bench/stream (first chunk)', $median(static fn () => $stream('/bench/stream')[0]), 1, 0, strlen($html), '-');
$row('GET /bench/stream (4 streamed boundaries)', $median(static fn () => $stream('/bench/stream')[1]), 1, 0, strlen($html), '-');

// --- The table -----------------------------------------------------------------------------
$head = ['scenario', 'median ms', 'requests', 'request B', 'response B', 'state cache'];
$widths = array_map(static fn (int $i): int => max(array_map(static fn (array $r): int => strlen($r[$i]), [$head, ...$rows])), array_keys($head));
$line = static fn (array $r): string => '| '.implode(' | ', array_map(static fn (string $v, int $w): string => str_pad($v, $w), $r, $widths)).' |';

echo $line($head), "\n", '|', implode('|', array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)), "|\n";
foreach ($rows as $r) {
    echo $line($r), "\n";
}
