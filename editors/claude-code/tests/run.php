<?php

/*
 * The tests of the Claude Code plugin (`make test-claude-code`): the phemc check hook, the MCP
 * launcher and the commands of the skills, on a copy of the fixture project of phemc-knowledge.
 * PHEMC_BIN is the phemc to test.
 */

declare(strict_types=1);

$plugin = dirname(__DIR__);
$phemc = getenv('PHEMC_BIN') ?: exit("PHEMC_BIN is not set\n");
$project = sys_get_temp_dir().'/phem-claude-code-'.getmypid();
$failures = 0;

exec('rm -rf '.escapeshellarg($project).' && cp -R '.escapeshellarg($plugin.'/../../compiler/crates/phemc-knowledge/tests/fixtures/project').' '.escapeshellarg($project));
file_put_contents($project.'/composer.json', '{"name": "acme/app"}');

// vendor/bin/phemc of the project, as Composer installs it: a PHP script (here, it runs PHEMC_BIN).
@mkdir($project.'/vendor/bin', 0777, true);
file_put_contents($project.'/vendor/bin/phemc', "#!/usr/bin/env php\n<?php\npassthru(escapeshellarg('$phemc').' '.implode(' ', array_map('escapeshellarg', array_slice(\$argv, 1))), \$code);\nexit(\$code);\n");
chmod($project.'/vendor/bin/phemc', 0755);

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    echo ($ok ? 'ok   ' : 'FAIL ').$name.($ok || '' === $detail ? '' : "\n     ".str_replace("\n", "\n     ", $detail))."\n";
}

/**
 * Runs a command with an input and an environment: [exit code, stdout, stderr].
 *
 * @param list<string>          $command
 * @param array<string, string> $env
 *
 * @return array{int, string, string}
 */
function run(array $command, string $input, string $dir, array $env = []): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env + getenv());
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);

    return [proc_close($process), (string) $out, (string) $err];
}

/** Runs the hook (its command in hooks.json) after a write of a file. */
function hook(string $file, array $env = [], string $tool = 'Write'): array
{
    global $plugin, $project;
    $event = ['session_id' => 't', 'cwd' => $project, 'hook_event_name' => 'PostToolUse', 'tool_name' => $tool, 'tool_input' => ['file_path' => $file]];

    return run(['sh', $plugin.'/bin/phem-check-hook'], json_encode($event), $project, $env);
}

// PHEMC_BIN is not set for the plugin: it finds vendor/bin/phemc of the project.
$env = ['PHEMC_BIN' => ''];

// The hook.
[$code, $out, $err] = hook($project.'/README.md', $env);
check('the hook skips a file that is not .phem', 0 === $code && '' === $out && '' === $err);

[$code, $out, $err] = hook($project.'/src/Ui/Card.phem', $env);
check('a valid file passes', 0 === $code && '' === $out && '' === $err, $out.$err);

file_put_contents($project.'/src/Ui/Broken.phem', "<?phem\nnamespace App\\Ui;\n\nuse Phem\\Attribute\\Component;\nuse Phem\\Node;\n\n#[Component]\nfunction Broken(): Node\n{\n    return <Card />;\n}\n");
[$code, $out, $err] = hook('src/Ui/Broken.phem', $env, 'Edit');
check('an error blocks with exit code 2 and its diagnostics on stderr', 2 === $code && str_contains($err, "phemc check src/Ui/Broken.phem: 1 error(s), 0 warning(s)\n- 10:13 error E011: `<Card>` needs the prop `title`"), "exit $code\n$err");
unlink($project.'/src/Ui/Broken.phem');

file_put_contents($project.'/src/Ui/List.phem', "<?phem\nnamespace App\\Ui;\n\nuse Phem\\Attribute\\Component;\nuse Phem\\Node;\n\n#[Component]\nfunction Names(array \$names): Node\n{\n    return <ul>{% for n in names %}<li>{{ n }}</li>{% endfor %}</ul>;\n}\n");
[$code, $out, $err] = hook($project.'/src/Ui/List.phem', $env);
$context = json_decode($out, true)['hookSpecificOutput']['additionalContext'] ?? '';
check('a warning goes to Claude as context and does not block', 0 === $code && str_contains($context, 'W002') && '' === $err, "exit $code\n$out$err");
unlink($project.'/src/Ui/List.phem');

rename($project.'/vendor/bin/phemc', $project.'/vendor/bin/phemc.off');
// A PATH with php and sh, and no phemc.
@mkdir($project.'/only-php');
symlink(PHP_BINARY, $project.'/only-php/php');
[$code, $out, $err] = hook($project.'/src/Ui/Card.phem', ['PHEMC_BIN' => '', 'PATH' => $project.'/only-php:/bin']);
check('with no phemc, the hook does nothing', 0 === $code && '' === $out, "exit $code\n$out$err");
[$code, $out, $err] = hook($project.'/src/Ui/Card.phem', ['PHEMC_BIN' => '', 'PATH' => '/nonexistent']);
check('with no PHP, the hook does nothing', 0 === $code && '' === $out && '' === $err, "exit $code\n$out$err");
rename($project.'/vendor/bin/phemc.off', $project.'/vendor/bin/phemc');

$installed = $project.'/vendor/composer/installed.json';
$saved = file_get_contents($installed);
file_put_contents($installed, '{"packages": [{"name": "acme/ui", "install-path": "../acme/ui", "extra": {"phem": {"source": "missing"}}}]}');
[$code, $out, $err] = hook($project.'/src/Ui/Card.phem', $env);
$context = json_decode($out, true)['hookSpecificOutput']['additionalContext'] ?? '';
check('a project that phemc cannot read does not block, and Claude knows that no check ran', 0 === $code && str_contains($context, 'did not run') && str_contains($context, 'missing'), "exit $code\n$out$err");
file_put_contents($installed, $saved);

[$code] = hook($project.'/src/Ui/Missing.phem', $env);
check('a removed file does not block', 0 === $code);

[$code] = run(['sh', $plugin.'/bin/phem-check-hook'], 'not json', $project);
check('a wrong input does not block', 0 === $code);

// The MCP launcher, in the project dir: vendor/bin/phemc.
$init = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']])."\n";
[$code, $out] = run(['sh', $plugin.'/bin/phem-mcp'], $init, '/', ['CLAUDE_PROJECT_DIR' => $project] + $env);
check('the launcher starts the MCP server of the project', 0 === $code && 'phem' === (json_decode($out, true)['result']['serverInfo']['name'] ?? null), "exit $code\n$out");

// The commands of the skills.
foreach (glob($plugin.'/skills/*/SKILL.md') as $skill) {
    $text = (string) file_get_contents($skill);
    preg_match_all('/^!`(.+)`$/m', $text, $commands);
    preg_match('/^---\n(.*?)\n---/s', $text, $frontmatter);
    check(basename(dirname($skill)).' has dynamic context', [] !== $commands[1]);
    check(basename(dirname($skill)).': the description is quoted when it has a `#`', !preg_match('/^description: [^"\'].* #/m', $frontmatter[1] ?? ''));
    foreach ($commands[1] as $command) {
        // A plain command, that `allowed-tools` lets run.
        check(basename(dirname($skill)).': `'.$command.'` is one plain command', 1 === preg_match('#^vendor/bin/phemc [a-z -]+$#', $command));
        [$code, $out] = run(['sh', '-c', $command], '', $project);
        check(basename(dirname($skill)).': `'.substr($command, 0, 40).'…` gives a text', 0 === $code && strlen($out) > 200, "exit $code\n".substr($out, 0, 300));
    }
}

// The manifests.
foreach (['.claude-plugin/plugin.json', '.mcp.json', '.lsp.json', 'hooks/hooks.json', '../../.claude-plugin/marketplace.json'] as $json) {
    check($json.' is valid JSON', null !== json_decode((string) file_get_contents($plugin.'/'.$json)));
}

$manifest = json_decode((string) file_get_contents($plugin.'/.claude-plugin/plugin.json'), true);
$marketplace = json_decode((string) file_get_contents($plugin.'/../../.claude-plugin/marketplace.json'), true);
check('the marketplace has the description of the plugin', $manifest['description'] === $marketplace['plugins'][0]['description']);

exec('rm -rf '.escapeshellarg($project));
echo 0 === $failures ? "\nAll the tests pass.\n" : "\n$failures test(s) fail.\n";
exit(0 === $failures ? 0 : 1);
