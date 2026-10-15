'use strict';

// Reads the declarations of a PHP or .rhp file: the namespace, the `use`
// imports, the functions, the classes, and the constants. For components, it
// also reads the props and the slots.

const { scan, maskText } = require('./scanner');

const MODIFIERS = new Set(['public', 'protected', 'private', 'readonly', 'static', 'final', 'abstract', 'var']);

// Splits the masked text into tokens.
function tokenize(code) {
    const tokens = [];
    const re = /#\[|\$[A-Za-z_\x80-￿][\w\x80-￿]*|\\?[A-Za-z_\x80-￿][\w\x80-￿]*(?:\\[A-Za-z_\x80-￿][\w\x80-￿]*)*\\?|\.\.\.|::|=>|->|\?->|\S/g;
    let m;
    while ((m = re.exec(code))) {
        const v = m[0];
        let t = 'punct';
        if (v[0] === '$') {
            t = 'var';
        } else if (v !== '#[' && /^[\\A-Za-z_\x80-￿]/.test(v)) {
            t = 'id';
        }
        tokens.push({ t, v, start: m.index, end: m.index + v.length });
    }
    return tokens;
}

function joinName(namespace, name) {
    return namespace ? namespace + '\\' + name : name;
}

function shortName(fqn) {
    return fqn.slice(fqn.lastIndexOf('\\') + 1);
}

function namespaceOf(fqn) {
    const i = fqn.lastIndexOf('\\');
    return i < 0 ? '' : fqn.slice(0, i);
}

class Parser {
    constructor(text) {
        this.text = text;
        this.tokens = tokenize(maskText(text, scan(text).masks));
        this.k = 0;
        this.namespace = '';
        this.result = {
            namespace: '',
            namespaceEnd: -1,       // offset after `namespace X;`
            openTagEnd: -1,         // offset after `<?php`
            uses: [],
            functions: [],
            classes: [],
            constants: [],
        };
        const open = /<\?php\b/.exec(text);
        if (open) {
            this.result.openTagEnd = open.index + open[0].length;
        }
    }

    peek(n = 0) {
        return this.tokens[this.k + n];
    }

    next() {
        return this.tokens[this.k++];
    }

    // Moves past the group that starts at the current token (`(`, `[`, `{`).
    skipGroup() {
        const open = this.next().v;
        const close = { '(': ')', '[': ']', '{': '}', '#[': ']' }[open];
        const opens = close === ']' ? ['[', '#['] : [open];
        let depth = 1;
        while (this.k < this.tokens.length && depth > 0) {
            const v = this.next().v;
            if (opens.includes(v)) {
                depth++;
            } else if (v === close) {
                depth--;
            }
        }
    }

    // Reads `#[A, B(...)]` and returns the attribute names.
    attribute() {
        const startK = this.k;
        this.skipGroup();
        const names = [];
        let depth = 0;
        for (let j = startK + 1; j < this.k - 1; j++) {
            const tok = this.tokens[j];
            if (tok.v === '(' || tok.v === '[') {
                depth++;
            } else if (tok.v === ')' || tok.v === ']') {
                depth--;
            } else if (depth === 0 && tok.t === 'id') {
                names.push(tok.v.replace(/^\\/, ''));
            }
        }
        return names;
    }

    parse() {
        let attrs = [];
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === '#[') {
                attrs.push(...this.attribute());
                continue;
            }
            if (tok.t !== 'id') {
                this.next();
                if (tok.v === ';' || tok.v === '}') {
                    attrs = [];
                }
                continue;
            }
            const word = tok.v.toLowerCase();
            const prev = this.tokens[this.k - 1];
            if (word === 'namespace' && this.peek(1) && (this.peek(1).t === 'id' || this.peek(1).v === '{')) {
                this.next();
                const name = this.peek().t === 'id' ? this.next().v.replace(/^\\/, '') : '';
                this.namespace = name;
                if (!this.result.namespace) {
                    this.result.namespace = name;
                    const end = this.peek();
                    this.result.namespaceEnd = end ? end.end : tok.end;
                }
                continue;
            }
            if (word === 'use' && this.peek(1) && this.peek(1).t === 'id') {
                this.use(tok);
                continue;
            }
            if (word === 'function' && this.peek(1) && this.peek(1).t === 'id' && this.peek(2) && this.peek(2).v === '(') {
                this.next();
                const name = this.next();
                const params = this.params();
                this.result.functions.push({
                    name: name.v,
                    fqn: joinName(this.namespace, name.v),
                    namespace: this.namespace,
                    params,
                    attrs,
                    offset: name.start,
                });
                attrs = [];
                continue;
            }
            if (['class', 'interface', 'trait', 'enum'].includes(word) && !(prev && (prev.v === '::' || prev.v.toLowerCase() === 'new'))) {
                this.next();
                const name = this.peek();
                if (name && name.t === 'id' && !/^(extends|implements)$/i.test(name.v)) {
                    this.next();
                    this.classLike(word, name, attrs);
                } else if (word === 'class') {
                    this.skipToBody();
                }
                attrs = [];
                continue;
            }
            if (word === 'new' && this.peek(1) && this.peek(1).v.toLowerCase() === 'class') {
                this.next();
                this.next();
                this.skipToBody();
                continue;
            }
            if (word === 'const' && this.peek(1) && this.peek(1).t === 'id' && this.peek(2) && this.peek(2).v === '=') {
                this.next();
                const name = this.next();
                this.result.constants.push({ name: name.v, fqn: joinName(this.namespace, name.v), offset: name.start });
                continue;
            }
            if (word === 'define' && this.peek(1) && this.peek(1).v === '(') {
                // define('NAME', ...): the name is masked as a string, so read it from the text.
                const m = /^\s*\(\s*(['"])([A-Za-z_][\w\\]*)\1/.exec(this.text.slice(tok.end, tok.end + 200));
                if (m) {
                    this.result.constants.push({ name: shortName(m[2]), fqn: m[2].replace(/^\\/, ''), offset: tok.start });
                }
            }
            this.next();
        }
        return this.result;
    }

    // Skips an anonymous class: its arguments, `extends`, and its body.
    skipToBody() {
        while (this.k < this.tokens.length && this.peek().v !== '{') {
            if (this.peek().v === '(') {
                this.skipGroup();
            } else {
                this.next();
            }
        }
        if (this.k < this.tokens.length) {
            this.skipGroup();
        }
    }

    // Reads `use A\B;`, `use function A\{B, C as D};`, and so on.
    use(tok) {
        this.next();
        let kind = 'class';
        if (/^(function|const)$/i.test(this.peek().v) && this.peek(1) && this.peek(1).t === 'id') {
            kind = this.next().v.toLowerCase();
        }
        const stmt = { kind, start: tok.start, end: tok.end, items: [], group: null };
        let prefix = '';
        while (this.k < this.tokens.length) {
            const t = this.next();
            if (t.v === ';') {
                stmt.end = t.end;
                break;
            }
            if (t.t !== 'id') {
                continue;
            }
            if (t.v.endsWith('\\') && this.peek() && this.peek().v === '{') {
                // A group: use A\{B, C};
                prefix = t.v.replace(/^\\/, '');
                const open = this.next();
                const items = [];
                while (this.k < this.tokens.length && this.peek().v !== '}' && this.peek().v !== ';') {
                    const it = this.next();
                    if (it.t !== 'id') {
                        continue;
                    }
                    let itemKind = kind;
                    let name = it.v;
                    if (/^(function|const)$/i.test(name) && this.peek().t === 'id') {
                        itemKind = name.toLowerCase();
                        name = this.next().v;
                    }
                    let alias = shortName(name);
                    if (this.peek() && this.peek().v.toLowerCase() === 'as') {
                        this.next();
                        alias = this.next().v;
                    }
                    items.push({ kind: itemKind, fqn: prefix + name, alias });
                }
                const close = this.peek() && this.peek().v === '}' ? this.next() : null;
                stmt.group = { prefix, open: open.start, close: close ? close.start : -1 };
                stmt.items.push(...items);
                continue;
            }
            const fqn = t.v.replace(/^\\/, '');
            let alias = shortName(fqn);
            if (this.peek() && this.peek().v.toLowerCase() === 'as') {
                this.next();
                alias = this.next().v;
            }
            stmt.items.push({ kind, fqn, alias });
        }
        this.result.uses.push(stmt);
    }

    // Reads the parameters of a function. The current token is `(`.
    params() {
        const params = [];
        this.next();
        let current = null;
        let depth = 0;
        let defaultStart = -1;
        const finish = (endOffset) => {
            if (current && current.name) {
                if (defaultStart >= 0) {
                    current.default = this.text.slice(defaultStart, endOffset).trim();
                }
                params.push(current);
            }
            current = null;
            defaultStart = -1;
        };
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (depth === 0 && tok.v === ')') {
                finish(tok.start);
                this.next();
                break;
            }
            if (tok.v === '#[') {
                const names = this.attribute();
                current = current || newParam();
                current.attrs.push(...names);
                continue;
            }
            this.next();
            if (tok.v === '(' || tok.v === '[' || tok.v === '{') {
                depth++;
                continue;
            }
            if (tok.v === ')' || tok.v === ']' || tok.v === '}') {
                depth--;
                continue;
            }
            if (depth > 0 || defaultStart >= 0) {
                if (depth === 0 && tok.v === ',') {
                    finish(tok.start);
                }
                continue;
            }
            if (tok.v === ',') {
                finish(tok.start);
                continue;
            }
            current = current || newParam();
            if (tok.t === 'var') {
                current.name = tok.v.slice(1);
            } else if (tok.v === '=') {
                defaultStart = tok.end;
            } else if (tok.v === '...') {
                current.variadic = true;
            } else if (tok.v === '&') {
                current.byRef = true;
            } else if (tok.t === 'id' && MODIFIERS.has(tok.v.toLowerCase())) {
                current.promoted = true;
            } else if (!current.name) {
                current.typeStart = current.typeStart ?? tok.start;
                current.typeEnd = tok.end;
            }
        }
        for (const p of params) {
            p.type = p.typeStart !== undefined ? this.text.slice(p.typeStart, p.typeEnd).replace(/\s+/g, '') : '';
            delete p.typeStart;
            delete p.typeEnd;
        }
        return params;
    }

    // Reads a class, an interface, a trait, or an enum.
    classLike(kind, name, attrs) {
        const cls = {
            kind,
            name: name.v,
            fqn: joinName(this.namespace, name.v),
            namespace: this.namespace,
            attrs,
            properties: [],
            methods: [],
            offset: name.start,
        };
        this.result.classes.push(cls);

        while (this.k < this.tokens.length && this.peek().v !== '{') {
            this.next();
        }
        if (this.k >= this.tokens.length) {
            return;
        }
        this.next();

        let memberAttrs = [];
        let modifiers = [];
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === '}') {
                this.next();
                return;
            }
            if (tok.v === '#[') {
                memberAttrs.push(...this.attribute());
                continue;
            }
            if (tok.v === '{') {
                this.skipGroup();
                continue;
            }
            if (tok.t === 'id' && MODIFIERS.has(tok.v.toLowerCase())) {
                modifiers.push(this.next().v.toLowerCase());
                continue;
            }
            if (tok.t === 'id' && tok.v.toLowerCase() === 'function') {
                this.next();
                const fname = this.next();
                const params = this.peek() && this.peek().v === '(' ? this.params() : [];
                cls.methods.push({ name: fname ? fname.v : '', params, modifiers, attrs: memberAttrs });
                // Skip the body (or the `;` of an abstract method).
                while (this.k < this.tokens.length && this.peek().v !== '{' && this.peek().v !== ';') {
                    this.next();
                }
                if (this.peek() && this.peek().v === '{') {
                    this.skipGroup();
                } else {
                    this.next();
                }
                memberAttrs = [];
                modifiers = [];
                continue;
            }
            if (tok.t === 'id' && /^(const|case|use)$/i.test(tok.v)) {
                this.skipStatement();
                memberAttrs = [];
                modifiers = [];
                continue;
            }
            if (modifiers.length > 0) {
                this.property(cls, modifiers, memberAttrs);
                memberAttrs = [];
                modifiers = [];
                continue;
            }
            this.next();
        }
    }

    skipStatement() {
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === ';') {
                this.next();
                return;
            }
            if (tok.v === '}') {
                return;
            }
            if (tok.v === '{' || tok.v === '(' || tok.v === '[') {
                this.skipGroup();
            } else {
                this.next();
            }
        }
    }

    // Reads `public ?Type $a = 1, $b;`. The current token is after the modifiers.
    property(cls, modifiers, attrs) {
        let typeStart = -1;
        let typeEnd = -1;
        let current = null;
        let defaultStart = -1;
        const finish = (endOffset) => {
            if (current) {
                if (defaultStart >= 0) {
                    current.default = this.text.slice(defaultStart, endOffset).trim();
                }
                cls.properties.push(current);
            }
            current = null;
            defaultStart = -1;
        };
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === ';' || tok.v === '}') {
                finish(tok.start);
                if (tok.v === ';') {
                    this.next();
                }
                return;
            }
            if (tok.v === '{' && current) {
                // Property hooks.
                finish(tok.start);
                this.skipGroup();
                return;
            }
            if (tok.v === '(' || tok.v === '[' || tok.v === '{') {
                this.skipGroup();
                continue;
            }
            this.next();
            if (defaultStart >= 0) {
                if (tok.v === ',') {
                    finish(tok.start);
                }
                continue;
            }
            if (tok.t === 'var') {
                current = {
                    name: tok.v.slice(1),
                    type: typeStart >= 0 ? this.text.slice(typeStart, typeEnd).replace(/\s+/g, '') : '',
                    modifiers,
                    attrs,
                };
            } else if (tok.v === '=') {
                defaultStart = tok.end;
            } else if (tok.v === ',') {
                finish(tok.start);
            } else if (!current) {
                typeStart = typeStart >= 0 ? typeStart : tok.start;
                typeEnd = tok.end;
            }
        }
    }
}

function newParam() {
    return { name: '', attrs: [], variadic: false, byRef: false, promoted: false };
}

function hasAttr(attrs, name) {
    return attrs.some((a) => a === name || a.endsWith('\\' + name));
}

function isHtmlType(type) {
    return /^\\?(Rephp\\)?Html$/.test(type.replace(/^\?/, '').replace(/^null\||\|null$/i, ''));
}

// Turns the parsed functions and classes into components.
function components(parsed) {
    const list = [];
    for (const fn of parsed.functions) {
        if (!/^[A-Z]/.test(fn.name)) {
            continue;
        }
        const comp = {
            kind: 'function',
            name: fn.name,
            fqn: fn.fqn,
            namespace: fn.namespace,
            page: hasAttr(fn.attrs, 'Page'),
            props: [],
            slots: [],
            children: false,
            rest: false,
            offset: fn.offset,
            signature: `function ${fn.name}(${fn.params.map(paramText).join(', ')})`,
        };
        for (const p of fn.params) {
            if (p.variadic) {
                comp.rest = true;
            } else if (p.name === 'children') {
                comp.children = true;
            } else if (isHtmlType(p.type)) {
                comp.slots.push(p.name);
            } else {
                comp.props.push({ name: p.name, type: p.type, default: p.default, required: p.default === undefined });
            }
        }
        list.push(comp);
    }
    for (const cls of parsed.classes) {
        if (cls.kind !== 'class' || !hasAttr(cls.attrs, 'Component')) {
            continue;
        }
        const comp = {
            kind: 'class',
            name: cls.name,
            fqn: cls.fqn,
            namespace: cls.namespace,
            page: hasAttr(cls.attrs, 'Page'),
            props: [],
            slots: [],
            children: false,
            rest: false,
            offset: cls.offset,
            signature: `#[Component]\nfinal class ${cls.name}`,
        };
        const lines = [];
        for (const p of cls.properties) {
            if (!p.modifiers.includes('public') || p.modifiers.includes('static') || hasAttr(p.attrs, 'State')) {
                continue;
            }
            if (p.name === 'children') {
                comp.children = true;
            } else if (isHtmlType(p.type)) {
                comp.slots.push(p.name);
            } else {
                const required = p.default === undefined && !p.type.startsWith('?') && !/(^|\|)null(\||$)/i.test(p.type) && p.type !== '';
                comp.props.push({ name: p.name, type: p.type, default: p.default, required });
            }
            lines.push(`    public ${p.type ? p.type + ' ' : ''}$${p.name}${p.default !== undefined ? ' = ' + p.default : ''};`);
        }
        if (lines.length > 0) {
            comp.signature += `\n{\n${lines.join('\n')}\n}`;
        }
        list.push(comp);
    }
    return list;
}

function paramText(p) {
    let s = '';
    if (p.type) {
        s += p.type + ' ';
    }
    if (p.byRef) {
        s += '&';
    }
    if (p.variadic) {
        s += '...';
    }
    s += '$' + p.name;
    if (p.default !== undefined) {
        s += ' = ' + p.default;
    }
    return s;
}

function parse(text) {
    const parsed = new Parser(text).parse();
    parsed.components = components(parsed);
    return parsed;
}

module.exports = { parse, shortName, namespaceOf, joinName };
