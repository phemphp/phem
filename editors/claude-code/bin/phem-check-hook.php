<?php

/*
 * The PostToolUse hook of the Phem plugin: after Claude writes or edits a `.phem` file, it runs
 * `phemc check <file>` in the project of the file.
 *
 * - An error: the hook prints the diagnostics on stderr and exits with code 2. Claude Code gives
 *   them to Claude, which corrects the file.
 * - Warnings only: the hook gives them to Claude as context, with exit code 0.
 * - Another file, a file outside the session project, or no phemc: the hook does nothing (exit
 *   0). The hook never runs a vendor/bin/phemc outside the project of the session.
 * - phemc gives no result (a project that it cannot read, a binary that it cannot download): the
 *   hook tells Claude that no check ran, with exit code 0, so it never stops the work for a
 *   reason that the file cannot fix.
 *
 * phemc: PHEMC_BIN, then vendor/bin/phemc of the project, then phemc on the PATH, as
 * bin/phem-mcp.
 */

declare(strict_types=1);

exit(phem_check_hook((string) stream_get_contents(STDIN), STDERR));

/**
 * @param resource $stderr
 */
function phem_check_hook(string $input, $stderr): int
{
    $event = json_decode($input, true);
    $file = is_array($event) ? ($event['tool_input']['file_path'] ?? null) : null;
    if (!is_string($file) || !str_ends_with($file, '.phem')) {
        return 0;
    }

    $cwd = is_string($event['cwd'] ?? null) ? $event['cwd'] : (string) getcwd();
    $file = phem_absolute($file, $cwd);
    $project = phem_project(dirname($file)) ?? $cwd;

    // Only the project of the session: the project is in the session dir, or the session runs in
    // a dir of the project. A file in another directory could come with its own vendor/bin/phemc.
    $session = realpath(getenv('CLAUDE_PROJECT_DIR') ?: $cwd);
    $real = realpath($project);
    if (!phem_inside($real, $session) && !phem_inside($session, $real)) {
        return 0;
    }

    $phemc = phem_phemc($project);
    if (null === $phemc || !is_file($file)) {
        return 0;
    }

    $path = str_starts_with($file, $project.'/') ? substr($file, strlen($project) + 1) : $file;
    [$json, , $error] = phem_run([...$phemc, 'check', $path, '--format', 'json'], $project);
    $result = json_decode($json, true);
    if (!is_array($result) || !is_array($result['files'] ?? null)) {
        $reason = trim(strtok(trim($error), "\n") ?: 'no output');
        phem_context(sprintf('phemc check %s did not run: %s. The file is not checked.', $path, $reason));

        return 0;
    }

    $lines = [];
    $errors = 0;
    foreach ($result['files'] as $f) {
        foreach ($f['diagnostics'] ?? [] as $d) {
            $errors += 'error' === $d['severity'] ? 1 : 0;
            $help = isset($d['help']) ? ' (help: '.$d['help'].')' : '';
            $lines[] = sprintf('- %d:%d %s %s: %s%s', $d['start']['line'] + 1, $d['start']['character'] + 1, $d['severity'], $d['code'], $d['message'], $help);
        }
    }

    if ([] === $lines) {
        return 0;
    }

    $report = sprintf("phemc check %s: %d error(s), %d warning(s)\n%s\n", $path, $errors, count($lines) - $errors, implode("\n", $lines));
    if (0 === $errors) {
        phem_context($report);

        return 0;
    }

    fwrite($stderr, $report.'Correct the errors of this file. `phemc components <name>` gives the props and the parts of a component.'."\n");

    return 2;
}

/** Whether a real path is a directory or a file in another one. */
function phem_inside(string|false $path, string|false $dir): bool
{
    return false !== $path && false !== $dir && ($path === $dir || str_starts_with($path, rtrim($dir, '/').'/'));
}

/** Gives a text to Claude as context, with no stop. */
function phem_context(string $text): void
{
    echo json_encode(['hookSpecificOutput' => ['hookEventName' => 'PostToolUse', 'additionalContext' => $text]]);
}

/** The absolute path of a file, with no `.` or `..` parts. */
function phem_absolute(string $file, string $cwd): string
{
    $path = str_starts_with($file, '/') ? $file : $cwd.'/'.$file;
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ('' === $part || '.' === $part) {
            continue;
        }

        if ('..' === $part) {
            array_pop($parts);
            continue;
        }

        $parts[] = $part;
    }

    return '/'.implode('/', $parts);
}

/** The project of a file: the nearest directory with a composer.json. */
function phem_project(string $dir): ?string
{
    while ('/' !== $dir && '.' !== $dir) {
        if (is_file($dir.'/composer.json')) {
            return $dir;
        }

        $dir = dirname($dir);
    }

    return null;
}

/**
 * The command of phemc for a project, or null.
 *
 * @return list<string>|null
 */
function phem_phemc(string $project): ?array
{
    $env = getenv('PHEMC_BIN');
    if (is_string($env) && '' !== $env) {
        return [$env];
    }

    if (is_file($project.'/vendor/bin/phemc')) {
        return [PHP_BINARY, $project.'/vendor/bin/phemc'];
    }

    [$found] = phem_run(['sh', '-c', 'command -v phemc'], $project);
    $found = trim($found);

    return '' === $found ? null : [$found];
}

/**
 * Runs a command in a directory: its stdout, its exit code and its stderr.
 *
 * @param list<string> $command
 *
 * @return array{string, int, string}
 */
function phem_run(array $command, string $dir): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
    if (!is_resource($process)) {
        return ['', 1, ''];
    }

    // The JSON of phemc is small, and its stderr too: read one pipe, then the other.
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [$out, proc_close($process), $err];
}
