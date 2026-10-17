'use strict';

// Twig in markup: the variables at a cursor, the open blocks, the type of a
// Twig expression, and the attributes of a value.
//
// The Twig variables of a markup literal are the PHP variables of the scope
// that contains it. In a class, a name is a local variable, then a property
// (`$this->name`), then a method (a handler). The Twig code before the cursor
// adds the variables of `{% for %}` and `{% set %}`.

const { scopeAt } = require('./scope');
const { TAGS, BLOCK_TAGS, LOOP } = require('./data/twig');

const LOOP_TYPE = { keys: Object.keys(LOOP).map((name) => ({ name, offset: -1, type: null, doc: LOOP[name] })) };

// Replaces the Twig comments with spaces.
function stripComments(s) {
    return s.replace(/\{#[\s\S]*?#\}/g, (c) => c.replace(/[^\n]/g, ' '));
}

// True when an `{% end<tag> %}` after `from` closes the tag. Nested tags with
// the same name need their own end tag.
function hasEndTag(text, from, tag) {
    const re = /\{%[-~]?\s*(\w+)/g;
    re.lastIndex = from;
    let depth = 0;
    let m;
    while ((m = re.exec(text))) {
        if (m[1] === tag) {
            depth++;
        } else if (m[1] === 'end' + tag) {
            if (depth === 0) {
                return true;
            }
            depth--;
        }
    }
    return false;
}

// The open blocks before `end` in the markup that starts at `from`:
// [{ tag, args, offset, sets: [{ name, expr, offset }] }]. The first frame
// is the top level (tag null).
function openBlocks(text, from, end) {
    const code = stripComments(text.slice(from, end));
    const stack = [{ tag: null, args: '', offset: from, sets: [] }];
    const re = /\{%[-~]?\s*(\w+)([\s\S]*?)[-~]?%\}/g;
    let m;
    while ((m = re.exec(code))) {
        const tag = m[1];
        const args = m[2];
        const offset = from + m.index + m[0].indexOf(tag);
        if (tag === 'set') {
            const set = /^\s*([A-Za-z_]\w*)\s*(=\s*([\s\S]*))?$/.exec(args);
            if (set) {
                stack[stack.length - 1].sets.push({ name: set[1], expr: set[3] ? set[3].trim() : '', offset: offset + args.indexOf(set[1]) + tag.length });
                if (!set[2]) {
                    stack.push({ tag, args, offset, sets: [] });
                }
            }
            continue;
        }
        // A tag that is not in the table (a new Twig tag, or a tag of an
        // extension) opens a block when its end tag follows.
        if (BLOCK_TAGS[tag] || (!TAGS[tag] && !tag.startsWith('end') && hasEndTag(text, from + m.index + m[0].length, tag))) {
            stack.push({ tag, args, offset, sets: [] });
            continue;
        }
        if (tag.startsWith('end')) {
            const open = tag.slice(3);
            for (let i = stack.length - 1; i > 0; i--) {
                if (stack[i].tag === open) {
                    stack.length = i;
                    break;
                }
            }
        }
    }
    return stack;
}

// Splits `a.b['c'][0]|first` into steps: [{ name }, { attr }, { key }, { index }, { filter }].
function steps(expr) {
    const list = [];
    const re = /^\s*([A-Za-z_]\w*)/;
    let m = re.exec(expr);
    if (!m) {
        return null;
    }
    list.push({ name: m[1] });
    let rest = expr.slice(m[0].length);
    while (rest.trim()) {
        if ((m = /^\s*\.\s*([A-Za-z_]\w*|\d+)/.exec(rest))) {
            list.push(/^\d/.test(m[1]) ? { index: m[1] } : { attr: m[1] });
        } else if ((m = /^\s*\[\s*(['"])(.*?)\1\s*\]/.exec(rest))) {
            list.push({ key: m[2] });
        } else if ((m = /^\s*\[[^\]]*\]/.exec(rest))) {
            list.push({ index: true });
        } else if ((m = /^\s*\|\s*(\w+)(\s*\((?:[^()]|\([^()]*\))*\))?/.exec(rest))) {
            list.push({ filter: m[1] });
        } else if ((m = /^\s*\((?:[^()]|\([^()]*\))*\)/.exec(rest))) {
            list.push({ call: true });
        } else {
            return null;
        }
        rest = rest.slice(m[0].length);
    }
    return list;
}

class TwigEnv {
    // `source` is { file, text, parsed }. `literal` is the start of the markup
    // literal; `end` is the start of the current Twig tag.
    constructor(source, literal, end, project) {
        this.source = source;
        this.literal = literal;
        this.end = end;
        this.project = project;
    }

    async build() {
        this.scope = await scopeAt(this.source, this.literal >= 0 ? this.literal : this.end, this.project);
        this.blocks = this.literal >= 0 ? openBlocks(this.source.text, this.literal, this.end) : [{ tag: null, sets: [] }];
        // Twig variables: name -> { name, kind, type, offset }. The outer blocks
        // come first, so an inner variable replaces an outer one.
        this.twigVars = new Map();
        for (const block of this.blocks) {
            if (block.tag === 'for') {
                const m = /^\s*([A-Za-z_]\w*)(?:\s*,\s*([A-Za-z_]\w*))?\s+in\s+([\s\S]*?)(?:\s+if\s+[\s\S]*)?$/.exec(block.args);
                if (m) {
                    const list = await this.typeOf(m[3]);
                    const item = m[2] || m[1];
                    const argsAt = block.offset + 3;
                    this.twigVars.set(item, { name: item, kind: 'twig', type: list && list.item ? list.item : null, offset: argsAt + block.args.indexOf(item), detail: '{% for %} item' });
                    if (m[2]) {
                        this.twigVars.set(m[1], { name: m[1], kind: 'twig', type: null, offset: argsAt + block.args.indexOf(m[1]), detail: '{% for %} key' });
                    }
                    this.twigVars.set('loop', { name: 'loop', kind: 'twig', type: LOOP_TYPE, offset: block.offset, detail: 'The loop variable' });
                }
            }
            for (const set of block.sets) {
                this.twigVars.set(set.name, { name: set.name, kind: 'twig', type: set.expr ? await this.typeOf(set.expr) : null, offset: set.offset, detail: '{% set %}' });
            }
        }
        return this;
    }

    // The tags of the open blocks, the innermost first.
    openTags() {
        return this.blocks.slice(1).map((b) => b.tag).reverse();
    }

    // All the variables, in the order of the Mamu rule:
    //   [{ name, kind: twig|param|local|property|method, type, offset, file, member }]
    async variables() {
        const list = [];
        const seen = new Set();
        const add = (v) => {
            if (!seen.has(v.name)) {
                seen.add(v.name);
                list.push({ file: this.source.file, ...v });
            }
        };
        for (const v of this.twigVars.values()) {
            add(v);
        }
        for (const v of this.scope.vars.values()) {
            add({ name: v.name, kind: v.kind, type: v.type, typeText: v.typeText, offset: v.offset });
        }
        const cls = this.scope.cls;
        if (cls && this.project) {
            const members = await this.project.classes.members(cls.fqn, this.source);
            for (const m of members) {
                if (m.kind === 'property' && !m.static) {
                    add({ name: m.name, kind: 'property', type: m.type, typeText: m.typeText, offset: m.offset, file: m.file, member: m });
                }
            }
            for (const m of members) {
                if (m.kind === 'method' && !m.static && !m.name.startsWith('__') && !/^(render|mount)$/i.test(m.name)) {
                    add({ name: m.name, kind: 'method', type: null, typeText: m.typeText, offset: m.offset, file: m.file, member: m });
                }
            }
        } else if (cls) {
            for (const p of cls.properties) {
                add({ name: p.name, kind: 'property', type: null, typeText: p.type, offset: p.offset });
            }
            for (const m of cls.methods) {
                if (!m.name.startsWith('__') && !/^(render|mount)$/i.test(m.name)) {
                    add({ name: m.name, kind: 'method', type: null, typeText: m.returnType, offset: m.offset });
                }
            }
        }
        return list;
    }

    async variable(name) {
        return (await this.variables()).find((v) => v.name === name) || null;
    }

    // The type of a Twig expression chain: `scientist.profession`, `items|first`.
    async typeOf(expr) {
        const list = steps(expr);
        if (!list) {
            return null;
        }
        const v = await this.variable(list[0].name);
        let type = v ? v.type : null;
        for (const step of list.slice(1)) {
            if (!type) {
                return null;
            }
            if (step.attr) {
                const attr = await this.attribute(type, step.attr);
                type = attr ? attr.type : null;
            } else if (step.key !== undefined) {
                const key = type.keys ? type.keys.find((k) => k.name === step.key) : null;
                type = key ? key.type : (type.item || null);
            } else if (step.index) {
                type = type.item || null;
            } else if (step.filter) {
                if (/^(first|last)$/.test(step.filter)) {
                    type = type.item || null;
                } else if (!/^(filter|sort|slice|reverse|merge|default|raw|shuffle)$/.test(step.filter)) {
                    type = null;
                }
            } else if (!step.call) {
                type = null;
            }
        }
        return type;
    }

    // The attributes of a value (the Twig rules: a key, a public property, a
    // public method, or a getter `getX()`/`isX()`/`hasX()` as `x`):
    //   [{ name, kind: key|property|method, type, member, key, getter }]
    async attributes(type) {
        if (!type) {
            return [];
        }
        if (type.keys) {
            return type.keys.map((k) => ({ name: k.name, kind: 'key', type: k.type, key: k }));
        }
        if (!type.fqn || !this.project) {
            return [];
        }
        const members = await this.project.classes.members(type.fqn, this.source);
        const list = [];
        const seen = new Set();
        for (const m of members) {
            if (m.kind === 'property' && !m.static && m.visibility === 'public') {
                seen.add(m.name);
                list.push({ name: m.name, kind: 'property', type: m.type, member: m });
            }
        }
        for (const m of members) {
            if (m.kind !== 'method' || m.static || m.visibility !== 'public' || m.name.startsWith('__')) {
                continue;
            }
            const required = (m.params || []).filter((p) => p.default === undefined && !p.variadic).length;
            const getter = /^(get|is|has)([A-Z]\w*)$/.exec(m.name);
            if (getter && required === 0) {
                const name = getter[2][0].toLowerCase() + getter[2].slice(1);
                if (!seen.has(name)) {
                    seen.add(name);
                    list.push({ name, kind: 'property', type: m.type, member: m, getter: true });
                    continue;
                }
            }
            if (!seen.has(m.name)) {
                seen.add(m.name);
                list.push({ name: m.name, kind: 'method', type: m.type, member: m, required });
            }
        }
        return list;
    }

    async attribute(type, name) {
        const list = await this.attributes(type);
        return list.find((a) => a.name === name) || list.find((a) => a.name.toLowerCase() === name.toLowerCase()) || null;
    }
}

async function twigEnv(source, literal, end, project) {
    return new TwigEnv(source, literal, end, project).build();
}

module.exports = { twigEnv, openBlocks, steps, LOOP_TYPE };
