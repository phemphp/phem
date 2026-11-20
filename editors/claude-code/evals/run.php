<?php

/*
 * The agent eval of Phem: real Claude Code sessions (`claude -p`) on the tasks of tasks.php, in
 * three conditions, scored with phemc. It uses the account of the user: each run costs about
 * $0.20 to $0.50.
 *
 *   php editors/claude-code/evals/run.php [--conditions=before,baseline,plugin] [--tasks=a,b]
 *       [--repeat=1] [--jobs=4] [--model=sonnet] [--docker | --phemc=/path/to/phemc] [--dry-run]
 *
 * `--dry-run` builds the apps and scores them with no session (no cost): a test of the eval.
 * `--rescore=<dir>` scores the runs of an earlier eval again, for example after a change of the
 * checks of a task.
 *
 * The conditions:
 * - before: phem/ui-base before the work for the agents (no examples, no aliases, fewer
 *   docblocks: the `--before` ref), and no plugin;
 * - baseline: phem/ui-base of the working tree, and no plugin;
 * - plugin: phem/ui-base of the working tree, and the Claude Code plugin of editors/claude-code.
 *
 * Each run gets a new app in var/agent-evals/<time>/<condition>/<task>-<n>/, with phem/phem and
 * phem/ui-base in vendor/, as Composer installs them. Its vendor/bin/phemc runs `--phemc`, or with
 * `--docker` the phemc of the dev image (docker/compose.yaml). A run passes when phemc check gives
 * no error, and the file has the tags and the patterns of the task. The results go to
 * results.json and summary.md.
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 3);
$options = getopt('', ['conditions:', 'tasks:', 'repeat:', 'jobs:', 'model:', 'docker', 'phemc:', 'before:', 'timeout:', 'dry-run', 'rescore:']);
$allTasks = require __DIR__.'/tasks.php';
$conditions = explode(',', $options['conditions'] ?? 'before,baseline,plugin');
$taskIds = isset($options['tasks']) ? explode(',', $options['tasks']) : array_keys($allTasks);
$repeat = (int) ($options['repeat'] ?? 1);
$jobs = max(1, (int) ($options['jobs'] ?? 4));
$model = $options['model'] ?? 'sonnet';
$before = $options['before'] ?? '88aff9e2';
$timeout = (int) ($options['timeout'] ?? 600);

// A rescore runs the vendor/bin/phemc of each app.
if (isset($options['rescore'])) {
    $out = rtrim($options['rescore'], '/');
    $results = array_map(score(...), array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['condition', 'task', 'n', 'dir'])), json_decode((string) file_get_contents("$out/results.json"), true)));
    file_put_contents("$out/results.json", json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    file_put_contents("$out/summary.md", summary($results));
    echo summary($results);
    exit(0);
}

if (!isset($options['docker']) && !isset($options['phemc'])) {
    fwrite(STDERR, "Give --phemc=<path of a phemc binary> or --docker.\n");
    exit(1);
}

foreach (array_diff($conditions, ['before', 'baseline', 'plugin']) as $unknown) {
    fwrite(STDERR, "Unknown condition: $unknown\n");
    exit(1);
}

foreach (array_diff($taskIds, array_keys($allTasks)) as $unknown) {
    fwrite(STDERR, "Unknown task: $unknown\n");
    exit(1);
}

$out = $repo.'/var/agent-evals/'.date('Ymd-His');
@mkdir($out, 0777, true);

// The runs, then their sessions, `jobs` at a time.
$runs = [];
foreach ($conditions as $condition) {
    foreach ($taskIds as $id) {
        for ($n = 1; $n <= $repeat; ++$n) {
            $dir = "$out/$condition/$id-$n";
            make_app($dir, $condition);
            $runs[] = ['condition' => $condition, 'task' => $id, 'n' => $n, 'dir' => $dir];
        }
    }
}

printf("%d run(s) in %s\n", count($runs), $out);

$running = [];
$queue = isset($options['dry-run']) ? [] : $runs;
while ([] !== $queue || [] !== $running) {
    while (count($running) < $jobs && [] !== $queue) {
        $run = array_shift($queue);
        $running[] = [$run, start_session($run), time()];
    }

    foreach ($running as $i => [$run, $process, $started]) {
        $status = proc_get_status($process);
        if ($status['running'] && time() - $started < $timeout) {
            continue;
        }

        // A session over the time: stop it, and its children (the MCP server, a hook).
        if ($status['running']) {
            exec('pkill -TERM -P '.(int) $status['pid']);
            proc_terminate($process);
        }

        proc_close($process);
        unset($running[$i]);
        printf("done %s/%s-%d\n", $run['condition'], $run['task'], $run['n']);
    }

    usleep(500_000);
}

$results = array_map(score(...), $runs);
file_put_contents("$out/results.json", json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
file_put_contents("$out/summary.md", summary($results));
echo "\n".summary($results);

/** A new app for a run: phem/phem and phem/ui-base in vendor/, and the shim of phemc. */
function make_app(string $dir, string $condition): void
{
    global $repo, $before;

    @mkdir("$dir/src/Ui", 0777, true);
    @mkdir("$dir/vendor/composer", 0777, true);
    @mkdir("$dir/vendor/bin", 0777, true);
    file_put_contents("$dir/composer.json", json_encode(['name' => 'acme/app', 'require' => ['phem/phem' => '^0.6', 'phem/ui-base' => '^0.1']], \JSON_PRETTY_PRINT)."\n");

    // phem/phem, as its Composer dist: the PHP code, with no docs.
    copy_tree("$repo/src", "$dir/vendor/phem/phem/src");
    copy("$repo/composer.json", "$dir/vendor/phem/phem/composer.json");
    copy("$repo/README.md", "$dir/vendor/phem/phem/README.md");

    // phem/ui-base: of the working tree, or of the `before` ref.
    $ui = "$dir/vendor/phem/ui-base";
    if ('before' === $condition) {
        foreach (explode("\n", trim((string) shell_exec('cd '.escapeshellarg($repo).' && git ls-tree -r --name-only '.escapeshellarg($before).' -- ui/base/src ui/base/assets ui/base/README.md ui/base/composer.json'))) as $file) {
            @mkdir(dirname("$ui/".substr($file, 8)), 0777, true);
            file_put_contents("$ui/".substr($file, 8), (string) shell_exec('cd '.escapeshellarg($repo).' && git show '.escapeshellarg("$before:$file")));
        }
    } else {
        foreach (['src', 'examples', 'assets'] as $sub) {
            copy_tree("$repo/ui/base/$sub", "$ui/$sub");
        }

        copy("$repo/ui/base/README.md", "$ui/README.md");
        copy("$repo/ui/base/composer.json", "$ui/composer.json");
    }

    $extra = json_decode((string) file_get_contents("$ui/composer.json"), true)['extra']['phem'];
    $installed = ['packages' => [
        ['name' => 'phem/phem', 'install-path' => '../phem/phem'],
        ['name' => 'phem/ui-base', 'install-path' => '../phem/ui-base', 'extra' => ['phem' => $extra]],
    ]];
    file_put_contents("$dir/vendor/composer/installed.json", json_encode($installed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

    // Read-only: the session can run it (Bash(vendor/bin/phemc:*)), not change it with Write or Edit.
    file_put_contents("$dir/vendor/bin/phemc", shim());
    chmod("$dir/vendor/bin/phemc", 0555);
}

/** The vendor/bin/phemc of the apps: a PHP script, as Composer installs it. */
function shim(): string
{
    global $repo, $options;

    if (!isset($options['docker'])) {
        $command = var_export([$options['phemc']], true);

        return "#!/usr/bin/env php\n<?php\n\$p = proc_open([...$command, ...array_slice(\$argv, 1)], [STDIN, STDOUT, STDERR], \$pipes);\nexit(proc_close(\$p));\n";
    }

    // The phemc of the dev image, in the same directory: the repository is /app in the container.
    $root = var_export($repo, true);

    return <<<PHP
        #!/usr/bin/env php
        <?php
        \$repo = $root;
        \$cwd = (string) getcwd();
        \$cmd = ['docker', 'compose', '-f', \$repo.'/docker/compose.yaml', 'run', '--rm', '-T', '-w', '/app'.substr(\$cwd, strlen(\$repo)), '--entrypoint', '/cargo-target/release/phemc', 'dev', ...array_slice(\$argv, 1)];
        \$p = proc_open(\$cmd, [STDIN, STDOUT, ['file', '/dev/null', 'w']], \$pipes);
        exit(proc_close(\$p));

        PHP;
}

function copy_tree(string $from, string $to): void
{
    exec('mkdir -p '.escapeshellarg(dirname($to)).' && cp -R '.escapeshellarg($from).' '.escapeshellarg($to));
}

/** Starts the Claude Code session of a run. */
function start_session(array $run): mixed
{
    global $repo, $allTasks, $model;

    // No `find`: `find -exec` runs any command. Glob lists the files.
    $tools = 'Read,Glob,Grep,Write,Edit,Bash(ls:*),Bash(cat:*),Bash(grep:*)';
    // Only the MCP servers of the condition: not the connectors of the account that runs the eval.
    $command = ['claude', '-p', $allTasks[$run['task']]['prompt'].' Do not run a build or a server. Stop when the file is written.', '--model', $model, '--setting-sources', 'project,local', '--output-format', 'stream-json', '--verbose', '--strict-mcp-config'];
    if ('plugin' === $run['condition']) {
        // The skills and the hook come from the plugin dir, and its MCP server from --mcp-config.
        $tools .= ',Skill,Bash(vendor/bin/phemc:*),mcp__phem';
        $mcp = ['mcpServers' => ['phem' => ['command' => 'sh', 'args' => ["$repo/editors/claude-code/bin/phem-mcp"]]]];
        array_push($command, '--plugin-dir', "$repo/editors/claude-code", '--mcp-config', json_encode($mcp, \JSON_UNESCAPED_SLASHES));
    }

    array_push($command, '--allowedTools', $tools);

    return proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $run['dir'].'/session.jsonl', 'w'], 2 => ['file', $run['dir'].'/session.err', 'w']], $pipes, $run['dir'], ['CLAUDE_PROJECT_DIR' => $run['dir']] + getenv());
}

/** The score of a run: the check, the tags, the patterns, and the numbers of the session. */
function score(array $run): array
{
    global $allTasks;

    $task = $allTasks[$run['task']];
    $file = $run['dir'].'/'.$task['file'];
    $code = is_file($file) ? (string) file_get_contents($file) : null;

    // A check that does not run (Docker is busy) is not a failure of the agent: try again.
    for ($try = 0; $try < 3 && !is_array($check = json_decode((string) shell_exec('cd '.escapeshellarg($run['dir']).' && vendor/bin/phemc check src --format json'), true)); ++$try) {
        sleep(2);
    }

    $errors = [];
    foreach ($check['files'] ?? [] as $f) {
        foreach ($f['diagnostics'] as $d) {
            if ('error' === $d['severity']) {
                $errors[] = $d['code'].' '.$d['message'];
            }
        }
    }

    // A tag in a comment or a docblock is not a use.
    $markup = null === $code ? null : (string) preg_replace(['#/\*.*?\*/#s', '#(?<![:"\'])//[^\n]*#', '/\{#.*?#\}/s'], '', $code);
    $missingTags = null === $markup ? $task['tags'] : array_values(array_filter($task['tags'], static fn (string $t): bool => 1 !== preg_match('/<'.$t.'\b/', $markup)));
    $missingMust = null === $code ? $task['must'] : array_values(array_filter($task['must'], static fn (string $re): bool => 1 !== preg_match($re, $code)));
    $forbidden = null === $code ? [] : array_values(array_filter($task['must_not'], static fn (string $re): bool => 1 === preg_match($re, $code)));

    $session = session_numbers($run['dir'].'/session.jsonl');
    $compiles = null !== $code && is_array($check) && [] === $errors;

    return $run + [
        'file' => null !== $code,
        'checked' => is_array($check),
        'compiles' => $compiles,
        'errors' => $errors,
        'missing_tags' => $missingTags,
        'missing_patterns' => $missingMust,
        'forbidden' => $forbidden,
        'pass' => $compiles && [] === $missingTags && [] === $missingMust && [] === $forbidden,
    ] + $session;
}

/** The numbers of a session: turns, time, cost, and the tools that it called. */
function session_numbers(string $file): array
{
    $tools = [];
    $result = [];
    foreach (is_file($file) ? file($file) : [] as $line) {
        $m = json_decode($line, true);
        if ('assistant' === ($m['type'] ?? null)) {
            foreach ($m['message']['content'] ?? [] as $c) {
                if ('tool_use' === ($c['type'] ?? null)) {
                    $name = preg_replace('/^mcp__(plugin_phem_)?phem__/', '', $c['name']);
                    $tools[$name] = ($tools[$name] ?? 0) + 1;
                }
            }
        }

        if ('result' === ($m['type'] ?? null)) {
            $result = $m;
        }
    }

    return [
        'turns' => $result['num_turns'] ?? null,
        'seconds' => isset($result['duration_ms']) ? round($result['duration_ms'] / 1000) : null,
        'cost' => $result['total_cost_usd'] ?? null,
        'tools' => $tools,
    ];
}

/** The table of the results, by condition, then the failures. */
function summary(array $results): string
{
    $md = "| Condition | Runs | Pass | Compiles | All tags | Mean turns | Mean time | Total cost |\n|---|---|---|---|---|---|---|---|\n";
    $by = [];
    foreach ($results as $r) {
        $by[$r['condition']][] = $r;
    }

    foreach ($by as $condition => $rs) {
        $n = count($rs);
        $mean = static fn (string $k): string => (string) round(array_sum(array_column($rs, $k)) / max(1, $n), 1);
        $md .= sprintf("| %s | %d | %d (%d %%) | %d | %d | %s | %s s | $%.2f |\n", $condition, $n, $p = count(array_filter($rs, static fn ($r) => $r['pass'])), $p * 100 / $n, count(array_filter($rs, static fn ($r) => $r['compiles'])), count(array_filter($rs, static fn ($r) => [] === $r['missing_tags'])), $mean('turns'), $mean('seconds'), array_sum(array_column($rs, 'cost')));
    }

    $md .= "\n| Task | ".implode(' | ', array_keys($by))." |\n|---|".str_repeat('---|', count($by))."\n";
    foreach (array_unique(array_column($results, 'task')) as $task) {
        $cells = [];
        foreach (array_keys($by) as $condition) {
            $rs = array_filter($results, static fn ($r) => $r['task'] === $task && $r['condition'] === $condition);
            $cells[] = implode(' ', array_map(static function (array $r): string {
                if ($r['pass']) {
                    return 'pass';
                }

                $why = match (true) {
                    !($r['checked'] ?? true) => 'no check: rescore it',
                    [] !== $r['errors'] => count($r['errors']).' error(s)',
                    [] !== $r['missing_tags'] => 'no '.implode(', ', $r['missing_tags']),
                    default => 'pattern',
                };

                return 'fail ('.($r['file'] ? $why : 'no file').')';
            }, $rs));
        }

        $md .= "| $task | ".implode(' | ', $cells)." |\n";
    }

    return $md;
}
