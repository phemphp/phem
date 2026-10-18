'use strict';

// The symbols of one project (a directory with a composer.json file):
//   - the classes from the Composer autoload maps (PSR-4 directories and the
//     class map). For speed, the index reads only the file names here.
//   - the functions and constants of the Composer "files" autoload entries;
//   - the components, functions, and classes of the .phpm files.
// The members of the classes come from the class index (classes.js), on demand.

const fs = require('fs');
const path = require('path');
const { parse, hasAttr, BUILTIN_COMPONENTS } = require('./php');
const { ClassIndex } = require('./classes');

const SKIP_DIRS = new Set(['vendor', 'node_modules', 'var', '.git', 'target', 'cache']);
const SKIP_VENDOR_DIRS = new Set(['Tests', 'tests', 'Test', 'test', 'Fixtures', 'fixtures', 'stubs']);
const MAX_FILES = 50000;

async function exists(file) {
    try {
        await fs.promises.access(file);
        return true;
    } catch {
        return false;
    }
}

async function readText(file) {
    try {
        return await fs.promises.readFile(file, 'utf8');
    } catch {
        return null;
    }
}

// Reads a Composer autoload map (autoload_psr4.php, autoload_classmap.php,
// autoload_files.php). Returns [key, [paths]] pairs.
function parseAutoloadMap(code, vendorDir) {
    const baseDir = path.dirname(vendorDir);
    const resolve = (expr) => {
        const m = /\$(vendorDir|baseDir)\s*\.\s*'([^']*)'/.exec(expr);
        if (m) {
            return path.join(m[1] === 'vendorDir' ? vendorDir : baseDir, m[2]);
        }
        const s = /^'([^']*)'$/.exec(expr.trim());
        return s ? s[1] : null;
    };
    const entries = [];
    const re = /'((?:[^'\\]|\\.)*)'\s*=>\s*(array\(([^)]*)\)|[^,\n]+)/g;
    let m;
    while ((m = re.exec(code))) {
        const key = m[1].replace(/\\\\/g, '\\');
        const exprs = m[3] !== undefined ? m[3].split(/,(?=\s*\$)/) : [m[2]];
        entries.push([key, exprs.map(resolve).filter(Boolean)]);
    }
    return entries;
}

class Project {
    constructor(root) {
        this.root = root;
        this.symbols = new Map();   // "kind:fqn" -> { kind, fqn, file }
        this.phpm = new Map();       // file -> parsed .phpm file
        this.classes = new ClassIndex(this);
        this.loading = null;
    }

    load() {
        if (!this.loading) {
            this.loading = this.build();
        }
        return this.loading;
    }

    reset() {
        this.loading = null;
    }

    async build() {
        this.symbols = new Map();
        this.phpm = new Map();
        const vendorDir = path.join(this.root, 'vendor');
        const composerDir = path.join(vendorDir, 'composer');

        if (await exists(path.join(composerDir, 'autoload_psr4.php'))) {
            await this.loadVendor(vendorDir);
        } else {
            await this.loadComposerJson();
        }
        await this.walkPhpm(this.root, true);
        return this;
    }

    add(kind, fqn, file) {
        const key = kind + ':' + fqn;
        if (!this.symbols.has(key)) {
            this.symbols.set(key, { kind, fqn, file });
        }
    }

    async loadVendor(vendorDir) {
        const composerDir = path.join(vendorDir, 'composer');
        const psr4 = parseAutoloadMap((await readText(path.join(composerDir, 'autoload_psr4.php'))) || '', vendorDir);
        const counter = { files: 0 };
        await Promise.all(psr4.map(async ([prefix, dirs]) => {
            for (const dir of dirs) {
                await this.walkPsr4(dir, prefix, dir.startsWith(vendorDir + path.sep), counter);
            }
        }));

        const classmap = parseAutoloadMap((await readText(path.join(composerDir, 'autoload_classmap.php'))) || '', vendorDir);
        for (const [fqn, [file]] of classmap) {
            if (!/^Composer\\|\\Tests?\\/.test(fqn)) {
                this.add('class', fqn, file);
            }
        }

        const files = parseAutoloadMap((await readText(path.join(composerDir, 'autoload_files.php'))) || '', vendorDir);
        await Promise.all(files.map(async ([, [file]]) => {
            const code = file && (await readText(file));
            if (code) {
                this.addDeclarations(parse(code), file);
            }
        }));
    }

    // Without vendor/, read the autoload section of composer.json.
    async loadComposerJson() {
        let json;
        try {
            json = JSON.parse((await readText(path.join(this.root, 'composer.json'))) || '{}');
        } catch {
            return;
        }
        const counter = { files: 0 };
        for (const section of [json.autoload, json['autoload-dev']]) {
            for (const [prefix, dirs] of Object.entries((section && section['psr-4']) || {})) {
                for (const dir of [].concat(dirs)) {
                    await this.walkPsr4(path.join(this.root, dir), prefix, false, counter);
                }
            }
        }
    }

    addDeclarations(parsed, file) {
        for (const fn of parsed.functions) {
            this.add('function', fn.fqn, file);
        }
        for (const cls of parsed.classes) {
            this.add('class', cls.fqn, file);
        }
        for (const c of parsed.constants) {
            this.add('const', c.fqn, file);
        }
    }

    // Each `Name.php` file in a PSR-4 directory is the class `Prefix\...\Name`.
    async walkPsr4(dir, prefix, isVendor, counter) {
        let entries;
        try {
            entries = await fs.promises.readdir(dir, { withFileTypes: true });
        } catch {
            return;
        }
        const subdirs = [];
        for (const entry of entries) {
            if (++counter.files > MAX_FILES) {
                return;
            }
            const full = path.join(dir, entry.name);
            let isDir = entry.isDirectory();
            if (entry.isSymbolicLink()) {
                try {
                    isDir = (await fs.promises.stat(full)).isDirectory();
                } catch {
                    continue;
                }
            }
            if (isDir) {
                if (/^[A-Za-z_]\w*$/.test(entry.name) && !(isVendor && SKIP_VENDOR_DIRS.has(entry.name)) && !SKIP_DIRS.has(entry.name)) {
                    subdirs.push([full, prefix + entry.name + '\\']);
                }
            } else if (/^[A-Za-z_]\w*\.php$/.test(entry.name) && /^[A-Z_]/.test(entry.name)) {
                this.add('class', prefix + entry.name.slice(0, -4), full);
            }
        }
        await Promise.all(subdirs.map(([d, p]) => this.walkPsr4(d, p, isVendor, counter)));
    }

    // Finds the .phpm files of the project. A subdirectory with its own
    // composer.json file is another project.
    async walkPhpm(dir, top) {
        if (!top && (await exists(path.join(dir, 'composer.json')))) {
            return;
        }
        let entries;
        try {
            entries = await fs.promises.readdir(dir, { withFileTypes: true });
        } catch {
            return;
        }
        const work = [];
        for (const entry of entries) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                if (!SKIP_DIRS.has(entry.name) && !entry.name.startsWith('.')) {
                    work.push(this.walkPhpm(full, false));
                }
            } else if (entry.name.endsWith('.phpm')) {
                work.push(this.updatePhpm(full));
            }
        }
        await Promise.all(work);
    }

    async updatePhpm(file, text) {
        text = text ?? (await readText(file));
        if (text === null) {
            this.phpm.delete(file);
            return;
        }
        this.phpm.set(file, parse(text));
    }

    deletePhpm(file) {
        this.phpm.delete(file);
    }

    // All the components of the project, and the built-in components. `current`
    // replaces the parsed version of one file (the open document, which can be unsaved).
    components(currentFile, current) {
        const list = [];
        for (const [file, parsed] of this.phpm) {
            if (file !== currentFile) {
                list.push(...parsed.components.map((c) => ({ ...c, file })));
            }
        }
        if (current) {
            list.push(...current.components.map((c) => ({ ...c, file: currentFile })));
        }
        list.push(...BUILTIN_COMPONENTS);
        return list;
    }

    // All the symbols of a kind ('class', 'function', 'const'), with the
    // declarations of the .phpm files.
    allSymbols(kind, currentFile, current) {
        const map = new Map();
        for (const s of this.symbols.values()) {
            if (s.kind === kind) {
                map.set(s.fqn, s);
            }
        }
        const addParsed = (parsed, file) => {
            const decls = kind === 'class' ? parsed.classes : kind === 'function' ? parsed.functions : parsed.constants;
            for (const d of decls) {
                map.set(d.fqn, { kind, fqn: d.fqn, file });
            }
        };
        for (const [file, parsed] of this.phpm) {
            if (file !== currentFile) {
                addParsed(parsed, file);
            }
        }
        if (current) {
            addParsed(current, currentFile);
        }
        return [...map.values()];
    }

    // The routes of the pages (`#[Page]` or `#[Route]` class components) of the .phpm files:
    //   [{ name, path, fqn, file, offset }]
    routes(currentFile, current) {
        const list = [];
        for (const [file, parsed] of this.phpm) {
            if (file !== currentFile) {
                list.push(...routesOf(parsed, file));
            }
        }
        if (current) {
            list.push(...routesOf(current, currentFile));
        }
        return list;
    }
}

// The routes of the pages of one parsed file.
function routesOf(parsed, file) {
    const list = [];
    for (const decl of parsed.classes) {
        const attrs = (decl.attributes || []).filter((a) => /(^|\\)(Page|Route)$/.test(a.name));
        if (attrs.length === 0 || !(hasAttr(decl.attrs, 'Component') || hasAttr(decl.attrs, 'Page'))) {
            continue;
        }
        attrs.forEach((attr, i) => {
            const name = /\bname\s*:\s*(['"])(.*?)\1/.exec(attr.args);
            const route = /^\s*(?:path\s*:\s*)?(['"])(.*?)\1/.exec(attr.args) || /\bpath\s*:\s*(['"])(.*?)\1/.exec(attr.args);
            list.push({
                name: name ? name[2] : routeName(decl.fqn) + (i > 0 ? '_' + i : ''),
                path: route ? route[2] : '',
                fqn: decl.fqn,
                file,
                offset: decl.offset,
            });
        });
    }
    return list;
}

// `App\Pages\Counter` → `app_pages_counter`, as in runtime/src/Routing/PageLoader.php.
function routeName(fqn) {
    return fqn.replace(/\\/g, '_').toLowerCase();
}

// Returns the directory of the nearest composer.json file, or `fallback`.
async function findRoot(file, fallback) {
    let dir = path.dirname(file);
    while (true) {
        if (await exists(path.join(dir, 'composer.json'))) {
            return dir;
        }
        const parent = path.dirname(dir);
        if (parent === dir || (fallback && dir === fallback)) {
            return fallback || path.dirname(file);
        }
        dir = parent;
    }
}

module.exports = { Project, findRoot, parseAutoloadMap, routesOf };
