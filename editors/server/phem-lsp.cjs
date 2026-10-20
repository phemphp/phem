'use strict';

// Runs the Phem language server (phem-lsp.wasm, the phemc-lsp crate compiled to WASI) with Node,
// on stdin and stdout. The server reads the files of the project at their own paths, so the
// root directory is open to it.
//
//     node --no-warnings phem-lsp.cjs

const fs = require('fs');
const path = require('path');
const { WASI } = require('node:wasi');

const root = path.parse(process.cwd()).root;
const wasi = new WASI({
    version: 'preview1',
    args: ['phem-lsp'],
    env: {},
    preopens: { [root]: root },
    returnOnExit: true,
});
const wasm = new WebAssembly.Module(fs.readFileSync(path.join(__dirname, 'phem-lsp.wasm')));
const instance = new WebAssembly.Instance(wasm, wasi.getImportObject());
process.exit(wasi.start(instance) ?? 0);
