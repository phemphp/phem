'use strict';

// The classes and functions of a project, read on demand: a class is parsed
// when completion or definition needs its members. The cache keeps each file
// until its mtime changes.
//
// A source is { file, text, parsed }. `current` is the source of the open
// document (its text can be unsaved); it replaces the file on disk. Each
// request makes a new `current`, so the lookups of one request are cached in
// `current.memo`.
//
// A member is:
//   { kind: 'property' | 'method' | 'constant', name, type, typeText, static,
//     visibility, signature, doc, file, offset, owner, enumCase }

const fs = require('fs');
const { parse, paramText } = require('./php');
const { toType, resolveName, docTags } = require('./types');

function sameName(a, b) {
    return a.toLowerCase() === b.toLowerCase();
}

function methodSignature(m, returnText) {
    const mods = [m.visibility, m.static ? 'static' : ''].filter(Boolean).join(' ');
    return `${mods} function ${m.name}(${m.params.map(paramText).join(', ')})${returnText ? ': ' + returnText : ''}`;
}

class ClassIndex {
    constructor(project) {
        this.project = project;
        this.files = new Map();     // file -> { file, text, parsed, mtime }
    }

    async source(file, current) {
        if (current && current.file === file) {
            return current;
        }
        let stat;
        try {
            stat = await fs.promises.stat(file);
        } catch {
            return null;
        }
        const hit = this.files.get(file);
        if (hit && hit.mtime === stat.mtimeMs) {
            return hit;
        }
        let text;
        try {
            text = await fs.promises.readFile(file, 'utf8');
        } catch {
            return null;
        }
        const entry = { file, text, parsed: parse(text), mtime: stat.mtimeMs };
        this.files.set(file, entry);
        return entry;
    }

    // The file that declares a class or a function, or null.
    fileOf(kind, fqn) {
        const project = this.project;
        const exact = project.symbols.get(kind + ':' + fqn);
        if (exact) {
            return exact.file;
        }
        for (const [file, parsed] of project.rhp) {
            const decls = kind === 'class' ? parsed.classes : parsed.functions;
            if (decls.some((d) => sameName(d.fqn, fqn))) {
                return file;
            }
        }
        for (const s of project.symbols.values()) {
            if (s.kind === kind && sameName(s.fqn, fqn)) {
                return s.file;
            }
        }
        return null;
    }

    // Caches `fn()` in the memo of the request, if there is one.
    cached(current, key, fn) {
        if (!current) {
            return fn();
        }
        current.memo = current.memo || new Map();
        if (!current.memo.has(key)) {
            current.memo.set(key, fn());
        }
        return current.memo.get(key);
    }

    // Finds a declaration: { decl, source } or null.
    find(kind, fqn, current) {
        if (!fqn) {
            return Promise.resolve(null);
        }
        return this.cached(current, `find:${kind}:${fqn.toLowerCase()}`, () => this.findUncached(kind, fqn, current));
    }

    async findUncached(kind, fqn, current) {
        const list = (src) => (kind === 'class' ? src.parsed.classes : src.parsed.functions);
        if (current) {
            const decl = list(current).find((d) => sameName(d.fqn, fqn));
            if (decl) {
                return { decl, source: current };
            }
        }
        const file = this.fileOf(kind, fqn);
        const source = file && (await this.source(file, current));
        if (!source) {
            return null;
        }
        const decl = list(source).find((d) => sameName(d.fqn, fqn));
        return decl ? { decl, source } : null;
    }

    // The parent class of a class declaration, as a FQN.
    parentOf(cls, source) {
        return cls.kind !== 'interface' && cls.extends[0] ? resolveName(source.parsed, cls.extends[0]) : null;
    }

    // All the members of a class: its own, then those of its traits, its
    // parents, and its interfaces. A member of a class hides a member with the
    // same name in a parent. `called` is the class for `static` types.
    members(fqn, current, called = fqn, seen = null) {
        if (!fqn) {
            return Promise.resolve([]);
        }
        if (seen === null) {
            return this.cached(current, `members:${fqn.toLowerCase()}:${(called || '').toLowerCase()}`, () => this.membersUncached(fqn, current, called, new Set()));
        }
        return this.membersUncached(fqn, current, called, seen);
    }

    async membersUncached(fqn, current, called, seen) {
        if (seen.has(fqn.toLowerCase())) {
            return [];
        }
        seen.add(fqn.toLowerCase());
        const found = await this.find('class', fqn, current);
        if (!found) {
            return [];
        }
        const { decl: cls, source } = found;
        const parent = this.parentOf(cls, source);
        const ctx = { parsed: source.parsed, self: cls.fqn, static: called, parent };
        const list = [];
        const names = new Set();
        const add = (m) => {
            const key = m.kind + ':' + (m.kind === 'property' ? m.name : m.name.toLowerCase());
            if (!names.has(key)) {
                names.add(key);
                list.push(m);
            }
        };
        const base = { file: source.file, owner: cls.fqn };

        const ctor = cls.methods.find((m) => sameName(m.name, '__construct'));
        const ctorTags = ctor ? docTags(ctor.doc) : null;
        for (const p of cls.properties) {
            const tags = docTags(p.doc);
            const typeText = tags.var || (p.promoted && ctorTags ? ctorTags.params[p.name] : '') || p.type;
            add({
                ...base,
                kind: 'property',
                name: p.name,
                type: toType(typeText, ctx) || toType(p.type, ctx),
                typeText,
                static: p.static,
                visibility: p.visibility,
                readonly: p.modifiers.includes('readonly'),
                signature: `${p.modifiers.join(' ')} ${p.type ? p.type + ' ' : ''}$${p.name}${p.default !== undefined ? ' = ' + p.default : ''}`,
                doc: tags.summary,
                offset: p.offset,
            });
        }
        for (const m of cls.methods) {
            const tags = docTags(m.doc);
            const typeText = tags.return || m.returnType;
            add({
                ...base,
                kind: 'method',
                name: m.name,
                type: toType(typeText, ctx) || toType(m.returnType, ctx),
                typeText,
                params: m.params,
                static: m.static,
                visibility: m.visibility,
                signature: methodSignature(m, typeText),
                doc: tags.summary,
                offset: m.offset,
            });
        }
        for (const c of cls.constants) {
            add({
                ...base,
                kind: 'constant',
                name: c.name,
                type: c.kind === 'case' ? { fqn: cls.fqn } : null,
                typeText: c.kind === 'case' ? cls.name : '',
                static: true,
                visibility: c.visibility,
                enumCase: c.kind === 'case',
                signature: c.kind === 'case' ? `case ${c.name}` : `const ${c.name}`,
                doc: docTags(c.doc).summary,
                offset: c.offset,
            });
        }
        if (cls.kind === 'enum') {
            for (const name of ['name', 'value']) {
                add({ ...base, kind: 'property', name, type: null, typeText: name === 'name' ? 'string' : 'int|string', static: false, visibility: 'public', readonly: true, signature: `public readonly ${name === 'name' ? 'string' : 'int|string'} $${name}`, doc: '', offset: cls.offset });
            }
        }

        // @property and @method tags of the class docblock.
        if (cls.doc) {
            const docStart = source.text.lastIndexOf(cls.doc, cls.start);
            const tags = docTags(cls.doc);
            for (const p of tags.properties) {
                add({ ...base, kind: 'property', name: p.name, type: toType(p.type, ctx), typeText: p.type, static: false, visibility: 'public', signature: `@property ${p.type} $${p.name}`, doc: '', offset: docStart + p.offset });
            }
            for (const m of tags.methods) {
                add({ ...base, kind: 'method', name: m.name, type: toType(m.type, ctx), typeText: m.type, params: [], paramsText: m.params, static: m.static, visibility: 'public', signature: `@method ${m.static ? 'static ' : ''}${m.type} ${m.name}(${m.params})`, doc: '', offset: docStart + m.offset });
            }
        }

        // PHP copies the trait members into the class: a private member of a
        // trait is a private member of the class.
        for (const t of cls.traits) {
            for (const m of await this.members(resolveName(source.parsed, t), current, called, seen)) {
                add({ ...m, owner: cls.fqn, trait: m.trait || m.owner });
            }
        }
        if (parent) {
            for (const m of await this.members(parent, current, called, seen)) {
                if (m.visibility !== 'private') {
                    add(m);
                }
            }
        }
        const interfaces = cls.kind === 'interface' ? cls.extends : cls.implements;
        for (const i of interfaces) {
            for (const m of await this.members(resolveName(source.parsed, i), current, called, seen)) {
                add(m);
            }
        }
        return list;
    }

    // One member by name. `kind` is 'property', 'method', 'constant', or null (any).
    async member(fqn, name, kind, current) {
        const members = await this.members(fqn, current);
        return members.find((m) => (!kind || m.kind === kind) && (m.kind === 'property' ? m.name === name : sameName(m.name, name))) || null;
    }

    // True if `fqn` is `ancestor` or extends it (for protected members).
    async isA(fqn, ancestor, current, seen = new Set()) {
        if (!fqn || !ancestor || seen.has(fqn.toLowerCase())) {
            return false;
        }
        if (sameName(fqn, ancestor)) {
            return true;
        }
        seen.add(fqn.toLowerCase());
        const found = await this.find('class', fqn, current);
        return found ? this.isA(this.parentOf(found.decl, found.source), ancestor, current, seen) : false;
    }
}

module.exports = { ClassIndex };
