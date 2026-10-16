'use strict';

// The PHP scope at an offset: the enclosing class and function, and the
// variables that are set before the offset, with their types. It also
// resolves the type of an expression chain: `$a->b()->c`, `Foo::bar()`,
// `new Foo()`, `$this->items[0]`.

const { scan, maskText } = require('./scanner');
const { tokenize, docBefore } = require('./php');
const { toType, resolveName, docTags } = require('./types');
const { importsOf } = require('./imports');

// The masked text of a source (strings, comments, and markup are spaces).
function codeOf(source) {
    if (source.code === undefined) {
        source.code = maskText(source.text, scan(source.text).masks);
    }
    return source.code;
}

// Returns the index after the group that opens at `i`, or tokens.length when
// the group does not close.
function skip(tokens, i) {
    const close = match(tokens, i);
    return close < 0 ? tokens.length : close + 1;
}

// The index of the token that closes the group at `i`, or -1.
function match(tokens, i) {
    const pairs = { '(': ')', '[': ']', '{': '}' };
    const open = tokens[i].v;
    const close = pairs[open];
    let depth = 0;
    for (let j = i; j < tokens.length; j++) {
        const v = tokens[j].v;
        if (v === open) {
            depth++;
        } else if (v === close && --depth === 0) {
            return j;
        }
    }
    return -1;
}

function enclosing(parsed, offset) {
    for (const cls of parsed.classes) {
        if (cls.bodyStart >= 0 && offset > cls.bodyStart && offset < cls.end) {
            const fn = cls.methods.find((m) => m.bodyStart >= 0 && offset > m.bodyStart && offset < m.bodyEnd);
            return { cls, fn: fn || null };
        }
    }
    const fn = parsed.functions.find((f) => f.bodyStart >= 0 && offset > f.bodyStart && offset < f.bodyEnd);
    return { cls: null, fn: fn || null };
}

class Scope {
    // `source` is { file, text, parsed }. `project` can be null.
    constructor(source, offset, project) {
        this.source = source;
        this.offset = offset;
        this.project = project;
        this.vars = new Map();      // name -> { name, type, typeText, offset, kind }
        this.thisType = null;
        const { cls, fn } = enclosing(source.parsed, offset);
        this.cls = cls;
        this.fn = fn;
        this.ctx = {
            parsed: source.parsed,
            self: cls ? cls.fqn : null,
            parent: cls && cls.extends[0] && cls.kind !== 'interface' ? resolveName(source.parsed, cls.extends[0]) : null,
        };
    }

    async build() {
        const { fn, cls } = this;
        if (!fn) {
            return this;
        }
        const tags = docTags(fn.doc);
        for (const p of fn.params) {
            this.param(p, tags.params[p.name]);
        }
        if (cls && !fn.static) {
            this.thisType = { fqn: cls.fqn };
        }
        const code = codeOf(this.source);
        const start = fn.bodyStart + 1;
        const tokens = tokenize(code.slice(start, this.offset)).map((t) => ({ ...t, start: t.start + start, end: t.end + start }));
        await this.walk(tokens);

        // /** @var Foo $x */ anywhere before the offset.
        const re = /@var\s+(\S+)\s+\$(\w+)/g;
        const body = this.source.text.slice(start, this.offset);
        let m;
        while ((m = re.exec(body))) {
            this.set(m[2], toType(m[1], this.ctx), start + m.index, m[1]);
        }
        return this;
    }

    param(p, docType) {
        const typeText = docType || p.type;
        let type = toType(typeText, this.ctx) || toType(p.type, this.ctx);
        if (p.variadic && type && !docType) {
            type = { item: type };
        }
        this.vars.set(p.name, { name: p.name, type, typeText, offset: p.offset, kind: 'param' });
    }

    // Sets a variable. The first declaration keeps its offset; a known type
    // replaces the type.
    set(name, type, offset, typeText = '') {
        const old = this.vars.get(name);
        if (old) {
            if (type) {
                old.type = type;
                old.typeText = typeText || old.typeText;
            }
            return;
        }
        this.vars.set(name, { name, type, typeText, offset, kind: 'local' });
    }

    async walk(tokens) {
        for (let i = 0; i < tokens.length; i++) {
            const tok = tokens[i];
            const next = tokens[i + 1];
            if (tok.t === 'var' && next && next.v === '=' && (!tokens[i + 2] || tokens[i + 2].v !== '=' && tokens[i + 2].v !== '>')) {
                const prev = tokens[i - 1];
                if (prev && (prev.v === '->' || prev.v === '::' || prev.v === '?->')) {
                    continue;
                }
                const name = tok.v.slice(1);
                const doc = docTags(docBefore(this.source.text, tok.start));
                const docType = doc.vars[name] || doc.var;
                const type = docType ? toType(docType, this.ctx) : await this.chain(tokens, i + 2);
                this.set(name, type, tok.start, docType);
                continue;
            }
            const word = tok.t === 'id' ? tok.v.toLowerCase() : '';
            if (word === 'foreach' && next && next.v === '(') {
                await this.foreach(tokens, i + 1);
                continue;
            }
            if (word === 'catch' && next && next.v === '(') {
                const type = tokens[i + 2];
                const variable = tokens.slice(i + 2, i + 8).find((t) => t.t === 'var');
                if (type && type.t === 'id' && variable) {
                    this.set(variable.v.slice(1), toType(type.v, this.ctx), variable.start, type.v);
                }
                continue;
            }
            if ((word === 'fn' || word === 'function') && next && next.v === '(') {
                this.closure(tokens, i, word);
            }
        }
    }

    // foreach ($items as $item) or foreach ($items as $key => $item)
    async foreach(tokens, open) {
        const close = match(tokens, open);
        const end = close < 0 ? tokens.length : close;
        const as = tokens.findIndex((t, j) => j > open && j < end && t.t === 'id' && t.v.toLowerCase() === 'as');
        if (as < 0) {
            return;
        }
        const listType = await this.chain(tokens.slice(0, as), open + 1);
        const vars = tokens.slice(as + 1, end).filter((t) => t.t === 'var');
        const hasKey = tokens.slice(as + 1, end).some((t) => t.v === '=>');
        if (vars.length === 0) {
            return;
        }
        const item = vars[vars.length - 1];
        this.set(item.v.slice(1), listType && listType.item ? listType.item : null, item.start);
        if (hasKey && vars.length > 1) {
            this.set(vars[0].v.slice(1), null, vars[0].start);
        }
    }

    // The parameters of a closure count when the offset is in its body: the
    // body does not close before the offset (the tokens end at the offset).
    closure(tokens, i, word) {
        const close = match(tokens, i + 1);
        if (close < 0) {
            return;
        }
        let j = close + 1;
        if (word === 'function') {
            while (j < tokens.length && tokens[j].v !== '{') {
                j++;
            }
            if (j >= tokens.length || match(tokens, j) >= 0) {
                return;
            }
        } else {
            while (j < tokens.length && tokens[j].v !== '=>') {
                j++;
            }
            let depth = 0;
            for (let k = j + 1; k < tokens.length; k++) {
                const v = tokens[k].v;
                if (v === '(' || v === '[' || v === '{') {
                    depth++;
                } else if (v === ')' || v === ']' || v === '}') {
                    if (--depth < 0) {
                        return;
                    }
                } else if (depth === 0 && (v === ',' || v === ';')) {
                    return;
                }
            }
        }
        // Read the parameters: [Type] [&] [...] $name [= default]
        let type = '';
        for (let k = i + 2; k < close; k++) {
            const t = tokens[k];
            if (t.t === 'var') {
                this.vars.set(t.v.slice(1), { name: t.v.slice(1), type: toType(type, this.ctx), typeText: type, offset: t.start, kind: 'param' });
                type = '';
                while (k < close && tokens[k].v !== ',') {
                    k++;
                }
            } else if (t.t === 'id' || t.v === '?' || t.v === '|') {
                type += t.v;
            }
        }
    }

    classType(name) {
        if (/^(self|static)$/i.test(name)) {
            return this.cls ? { fqn: this.cls.fqn } : null;
        }
        if (/^parent$/i.test(name)) {
            return this.ctx.parent ? { fqn: this.ctx.parent } : null;
        }
        const fqn = resolveName(this.source.parsed, name);
        return fqn ? { fqn } : null;
    }

    // The function that a call `name(...)` calls: { decl, source } or null.
    async findFunction(name) {
        if (!this.project) {
            return null;
        }
        const parsed = this.source.parsed;
        const candidates = [];
        if (name.startsWith('\\')) {
            candidates.push(name.slice(1));
        } else {
            const [first, ...rest] = name.split('\\');
            const imported = importsOf(parsed, rest.length ? 'class' : 'function').find((i) => i.alias.toLowerCase() === first.toLowerCase());
            if (imported) {
                candidates.push([imported.fqn, ...rest].join('\\'));
            }
            if (parsed.namespace) {
                candidates.push(parsed.namespace + '\\' + name);
            }
            candidates.push(name);
        }
        for (const fqn of candidates) {
            const found = await this.project.classes.find('function', fqn, this.source);
            if (found) {
                return found;
            }
        }
        return null;
    }

    async functionType(name) {
        const found = await this.findFunction(name);
        if (!found) {
            return null;
        }
        const ctx = { parsed: found.source.parsed };
        return toType(docTags(found.decl.doc).return, ctx) || toType(found.decl.returnType, ctx);
    }

    async member(type, name, kind) {
        if (!type || !type.fqn || !this.project) {
            return null;
        }
        return this.project.classes.member(type.fqn, name, kind, this.source);
    }

    // An array literal: a shape with its keys, or a list of shapes.
    async shape(tokens, i) {
        const close = match(tokens, i);
        const end = close < 0 ? tokens.length : close;
        const keys = [];
        let first = null;
        let depth = 0;
        for (let k = i + 1; k < end; k++) {
            const t = tokens[k];
            if (t.v === '(' || t.v === '[' || t.v === '{') {
                if (depth === 0 && t.v === '[' && first === null && keys.length === 0) {
                    first = k;
                }
                depth++;
                continue;
            }
            if (t.v === ')' || t.v === ']' || t.v === '}') {
                depth--;
                continue;
            }
            if (depth === 0 && t.v === '=>') {
                const before = this.source.text.slice(tokens[k - 1] ? tokens[k - 1].end : tokens[i].end, t.start);
                const m = /(['"])((?:\\.|(?!\1).)*)\1\s*$/.exec(before);
                if (m) {
                    const offset = (tokens[k - 1] ? tokens[k - 1].end : tokens[i].end) + m.index;
                    const value = tokens[k + 1];
                    const type = value && value.v === '[' ? await this.shape(tokens, k + 1) : await this.chain(tokens, k + 1);
                    keys.push({ name: m[2], offset, type });
                }
            }
        }
        if (keys.length) {
            return { keys };
        }
        if (first !== null) {
            return { item: await this.shape(tokens, first) };
        }
        return null;
    }

    // The type of the expression chain that starts at token `i`.
    async chain(tokens, i, end = tokens.length) {
        let tok = tokens[i];
        if (!tok || i >= end) {
            return null;
        }
        let type = null;
        const word = tok.t === 'id' ? tok.v.toLowerCase() : '';
        if (word === 'clone' || word === 'yield' || word === 'await') {
            return this.chain(tokens, i + 1, end);
        }
        if (word === 'new' && tokens[i + 1] && tokens[i + 1].t === 'id') {
            type = this.classType(tokens[i + 1].v);
            i += 2;
            if (tokens[i] && tokens[i].v === '(') {
                i = skip(tokens, i);
            }
        } else if (tok.v === '(') {
            const close = match(tokens, i);
            if (close < 0) {
                return null;
            }
            type = await this.chain(tokens, i + 1, close);
            i = close + 1;
        } else if (tok.t === 'var') {
            if (tok.v === '$this') {
                type = this.thisType;
            } else {
                const v = this.vars.get(tok.v.slice(1));
                type = v ? v.type : null;
            }
            i++;
        } else if (tok.t === 'id' && tokens[i + 1] && tokens[i + 1].v === '::') {
            type = this.classType(tok.v);
            i++;
        } else if (tok.t === 'id' && tokens[i + 1] && tokens[i + 1].v === '(') {
            type = await this.functionType(tok.v);
            i = skip(tokens, i + 1);
        } else if (tok.v === '[') {
            type = await this.shape(tokens, i);
            i = skip(tokens, i);
        } else {
            return null;
        }

        while (i < end && type) {
            tok = tokens[i];
            const name = tokens[i + 1];
            if ((tok.v === '->' || tok.v === '?->') && name && name.t === 'id') {
                const call = tokens[i + 2] && tokens[i + 2].v === '(';
                const m = await this.member(type, name.v, call ? 'method' : 'property');
                type = m ? m.type : null;
                i = call ? skip(tokens, i + 2) : i + 2;
            } else if (tok.v === '::' && name) {
                if (name.t === 'var') {
                    const m = await this.member(type, name.v.slice(1), 'property');
                    type = m ? m.type : null;
                    i += 2;
                } else if (name.t === 'id' && tokens[i + 2] && tokens[i + 2].v === '(') {
                    const m = await this.member(type, name.v, 'method');
                    type = m ? m.type : null;
                    i = skip(tokens, i + 2);
                } else if (name.t === 'id' && name.v.toLowerCase() !== 'class') {
                    const m = await this.member(type, name.v, 'constant');
                    type = m ? m.type : null;
                    i += 2;
                } else {
                    return null;
                }
            } else if (tok.v === '[') {
                if (type.keys) {
                    const key = /^\s*\[\s*(['"])(.*?)\1\s*\]/.exec(this.source.text.slice(tok.start));
                    const entry = key && type.keys.find((k) => k.name === key[2]);
                    type = entry ? entry.type : null;
                } else {
                    type = type.item || null;
                }
                i = skip(tokens, i);
            } else {
                break;
            }
        }
        return type;
    }

    // The type of the PHP expression in [start, end) of the source.
    async typeOfRange(start, end) {
        const code = codeOf(this.source);
        const tokens = tokenize(code.slice(start, end)).map((t) => ({ ...t, start: t.start + start, end: t.end + start }));
        return this.chain(tokens, 0);
    }
}

async function scopeAt(source, offset, project) {
    return new Scope(source, offset, project).build();
}

// The start of the expression chain that ends at `end` in the masked code:
// `$this->a->b(1)` in `x = $this->a->b(1)`.
function chainStart(code, end) {
    let i = end;
    while (true) {
        while (i > 0 && /\s/.test(code[i - 1])) {
            i--;
        }
        const c = code[i - 1];
        if (c === ')' || c === ']') {
            const open = c === ')' ? '(' : '[';
            let depth = 0;
            let j = i - 1;
            for (; j >= 0; j--) {
                if (code[j] === c) {
                    depth++;
                } else if (code[j] === open && --depth === 0) {
                    break;
                }
            }
            if (j < 0) {
                return i;
            }
            i = j;
            continue;
        }
        const word = /[$\\\w]+$/.exec(code.slice(Math.max(0, i - 256), i));
        if (word) {
            i -= word[0].length;
            const before = code.slice(0, i).trimEnd();
            if (/^new$/i.test(word[0])) {
                return i;
            }
            if (/(->|\?->|::)$/.test(before)) {
                i = before.length - (before.endsWith('?->') ? 3 : 2);
                continue;
            }
            if (/\bnew$/i.test(before)) {
                return before.length - 3;
            }
            return i;
        }
        return i;
    }
}

module.exports = { scopeAt, Scope, codeOf, chainStart, enclosing };
