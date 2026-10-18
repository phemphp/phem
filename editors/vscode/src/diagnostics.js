'use strict';

// Runs `phemc check <sourceDir> --format json` and reads its diagnostics. It
// does not use the vscode API.
//
// check() returns a Map: absolute file -> [{ severity, code, message, start, end }]
// `start` and `end` are { line, character }, as VS Code counts them.

const { execFile } = require('child_process');
const path = require('path');

// Turns the JSON of `phemc check --format json` into diagnostics per file.
function toDiagnostics(json, sourceDir) {
    const map = new Map();
    for (const f of (json && json.files) || []) {
        const file = path.resolve(sourceDir, f.file);
        map.set(file, (f.diagnostics || []).map((d) => ({
            severity: d.severity === 'warning' ? 'warning' : 'error',
            code: d.code,
            message: d.message + (d.label ? ` (${d.label})` : '') + (d.help ? `\nhelp: ${d.help}` : ''),
            start: d.start,
            end: d.end,
        })));
    }
    return map;
}

// Runs phemc in the project root. An exit code of 1 is normal: the files have
// errors. It rejects when phemc does not start or prints no JSON.
function check(command, root, sourceDir, exec = execFile) {
    return new Promise((resolve, reject) => {
        exec(command, ['check', sourceDir, '--format', 'json'], { cwd: root, maxBuffer: 32 * 1024 * 1024, timeout: 120000 }, (err, stdout, stderr) => {
            if (err && typeof err.code === 'string') {
                // ENOENT, EACCES: the command does not start.
                reject(err);
                return;
            }
            let json;
            try {
                json = JSON.parse(stdout);
            } catch {
                reject(new Error(String(stderr || '').trim() || String(stdout || '').trim() || (err ? err.message : 'phemc printed no JSON')));
                return;
            }
            resolve(toDiagnostics(json, path.resolve(root, sourceDir)));
        });
    });
}

module.exports = { check, toDiagnostics };
