'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { check } = require('../src/diagnostics');

const JSON_OUTPUT = {
    files: [{
        file: 'Components/Card.phpm',
        diagnostics: [
            { severity: 'error', code: 'E010', message: 'unknown component `<Avatr>`', label: null, help: 'check the `use` imports', start: { line: 30, character: 13 }, end: { line: 30, character: 18 } },
            { severity: 'warning', code: 'W001', message: 'a warning', label: 'here', help: null, start: { line: 1, character: 0 }, end: { line: 1, character: 2 } },
        ],
    }],
};

// A fake mamuc: a script that prints `output` and exits with `code`.
function fakeMamuc(dir, output, code) {
    const file = path.join(dir, 'mamuc');
    fs.writeFileSync(file, `#!/bin/sh\necho "$@" > "${path.join(dir, 'args')}"\ncat <<'JSON'\n${output}\nJSON\nexit ${code}\n`);
    fs.chmodSync(file, 0o755);
    return file;
}

test('check() runs mamuc and reads the diagnostics, also with exit code 1', { skip: process.platform === 'win32' }, async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mamu-diag-'));
    try {
        const mamuc = fakeMamuc(dir, JSON.stringify(JSON_OUTPUT), 1);
        const map = await check(mamuc, dir, 'src');
        assert.equal(fs.readFileSync(path.join(dir, 'args'), 'utf8').trim(), 'check src --format json');
        const list = map.get(path.join(dir, 'src', 'Components', 'Card.phpm'));
        assert.equal(list.length, 2);
        assert.deepEqual(list[0], {
            severity: 'error',
            code: 'E010',
            message: 'unknown component `<Avatr>`\nhelp: check the `use` imports',
            start: { line: 30, character: 13 },
            end: { line: 30, character: 18 },
        });
        assert.equal(list[1].severity, 'warning');
        assert.equal(list[1].message, 'a warning (here)');

        await assert.rejects(check(fakeMamuc(dir, 'mamuc: source directory `src` does not exist', 1), dir, 'src'), /does not exist/);
        await assert.rejects(check(path.join(dir, 'missing'), dir, 'src'), { code: 'ENOENT' });
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
});
