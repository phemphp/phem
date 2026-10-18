'use strict';

// Reads the declarations of a PHP or .phpm file: the namespace, the `use`
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
            namespaceStart: -1,     // offset of the name in `namespace X;`
            namespaceEnd: -1,       // offset after `namespace X;`
            openTagEnd: -1,         // offset after `<?phpm` (or `<?php` in a PHP class)
            uses: [],
            functions: [],
            classes: [],
            constants: [],
        };
        const open = /<\?phpm?\b/.exec(text);
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

    // Returns the index of the token that closes the group at token `k`, or -1.
    matchIndex(k) {
        const open = this.tokens[k].v;
        const close = { '(': ')', '[': ']', '{': '}', '#[': ']' }[open];
        const opens = close === ']' ? ['[', '#['] : [open];
        let depth = 0;
        for (let j = k; j < this.tokens.length; j++) {
            const v = this.tokens[j].v;
            if (opens.includes(v)) {
                depth++;
            } else if (v === close && --depth === 0) {
                return j;
            }
        }
        return -1;
    }

    // Reads `#[A, B(...)]` and returns the attributes: [{ name, args }]. `args`
    // is the text between the parentheses, or ''.
    attribute() {
        const startK = this.k;
        this.skipGroup();
        const list = [];
        let depth = 0;
        for (let j = startK + 1; j < this.k - 1; j++) {
            const tok = this.tokens[j];
            if (tok.v === '(' || tok.v === '[') {
                if (depth === 0 && tok.v === '(' && list.length > 0) {
                    const close = this.matchIndex(j);
                    list[list.length - 1].args = this.text.slice(tok.end, close < 0 ? this.text.length : this.tokens[close].start);
                }
                depth++;
            } else if (tok.v === ')' || tok.v === ']') {
                depth--;
            } else if (depth === 0 && tok.t === 'id') {
                list.push({ name: tok.v.replace(/^\\/, ''), args: '' });
            }
        }
        return list;
    }

    // Reads `: Type` after the parameters. Returns the type, or ''.
    returnType() {
        if (!this.peek() || this.peek().v !== ':') {
            return '';
        }
        this.next();
        let start = -1;
        let end = -1;
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === '{' || tok.v === ';' || tok.v === '=>' || (tok.t === 'id' && /^(use)$/i.test(tok.v))) {
                break;
            }
            if (tok.t !== 'id' && !['?', '|', '&', '(', ')', 'static'].includes(tok.v)) {
                break;
            }
            start = start < 0 ? tok.start : start;
            end = tok.end;
            this.next();
        }
        return start < 0 ? '' : this.text.slice(start, end).replace(/\s+/g, '');
    }

    // The range of the body that starts at the current token, if it is `{`.
    // It does not move. A body that does not close (the user types in it) ends
    // at the end of the text.
    bodyRange() {
        const tok = this.peek();
        if (!tok || tok.v !== '{') {
            return { bodyStart: -1, bodyEnd: -1 };
        }
        const close = this.matchIndex(this.k);
        return { bodyStart: tok.start, bodyEnd: close < 0 ? this.text.length : this.tokens[close].end };
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
                    this.result.namespaceStart = name ? this.tokens[this.k - 1].start : -1;
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
                const returnType = this.returnType();
                const body = this.bodyRange();
                this.result.functions.push({
                    name: name.v,
                    fqn: joinName(this.namespace, name.v),
                    namespace: this.namespace,
                    params,
                    returnType,
                    attrs: attrs.map((a) => a.name),
                    attributes: attrs,
                    doc: docBefore(this.text, tok.start),
                    offset: name.start,
                    start: tok.start,
                    ...body,
                    end: body.bodyEnd >= 0 ? body.bodyEnd : name.end,
                });
                attrs = [];
                continue;
            }
            // `$a->class`, `Foo::class`, and `f(class: 1)` are not declarations.
            const name = this.peek(1);
            if (['class', 'interface', 'trait', 'enum'].includes(word) && !(prev && ['::', '->', '?->'].includes(prev.v))
                && !(prev && prev.v.toLowerCase() === 'new') && name && name.t === 'id' && !/^(extends|implements)$/i.test(name.v)) {
                this.next();
                this.next();
                this.classLike(word, name, attrs, tok);
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
                this.result.constants.push({ name: name.v, fqn: joinName(this.namespace, name.v), offset: name.start, doc: docBefore(this.text, tok.start) });
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
                    if (/^(function|const)$/i.test(name) && this.peek() && this.peek().t === 'id') {
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
                const list = this.attribute();
                current = current || newParam();
                current.attrs.push(...list.map((a) => a.name));
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
                current.offset = tok.start;
            } else if (tok.v === '=') {
                defaultStart = tok.end;
            } else if (tok.v === '...') {
                current.variadic = true;
            } else if (tok.v === '&') {
                current.byRef = true;
            } else if (tok.t === 'id' && MODIFIERS.has(tok.v.toLowerCase())) {
                current.promoted = true;
                current.modifiers.push(tok.v.toLowerCase());
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
    classLike(kind, name, attrs, keyword) {
        const cls = {
            kind,
            name: name.v,
            fqn: joinName(this.namespace, name.v),
            namespace: this.namespace,
            attrs: attrs.map((a) => a.name),
            attributes: attrs,
            doc: docBefore(this.text, keyword.start),
            extends: [],
            implements: [],
            traits: [],
            properties: [],
            methods: [],
            constants: [],
            offset: name.start,
            start: keyword.start,
            bodyStart: -1,
            end: name.end,
        };
        this.result.classes.push(cls);

        // extends A, implements B, C (an interface can extend more than one).
        let list = null;
        while (this.k < this.tokens.length && this.peek().v !== '{') {
            const tok = this.next();
            if (tok.t !== 'id') {
                continue;
            }
            if (/^extends$/i.test(tok.v)) {
                list = cls.extends;
            } else if (/^implements$/i.test(tok.v)) {
                list = cls.implements;
            } else if (list) {
                list.push(tok.v);
            }
        }
        if (this.k >= this.tokens.length) {
            return;
        }
        const body = this.bodyRange();
        cls.bodyStart = body.bodyStart;
        cls.end = body.bodyEnd;
        this.next();

        let memberAttrs = [];
        let modifiers = [];
        let memberStart = -1;
        const reset = () => {
            memberAttrs = [];
            modifiers = [];
            memberStart = -1;
        };
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === '}') {
                this.next();
                return;
            }
            if (memberStart < 0) {
                memberStart = tok.start;
            }
            if (tok.v === '#[') {
                memberAttrs.push(...this.attribute().map((a) => a.name));
                continue;
            }
            if (tok.v === '{') {
                this.skipGroup();
                reset();
                continue;
            }
            if (tok.t === 'id' && MODIFIERS.has(tok.v.toLowerCase())) {
                modifiers.push(this.next().v.toLowerCase());
                continue;
            }
            if (tok.t === 'id' && tok.v.toLowerCase() === 'function') {
                this.next();
                const byRef = this.peek() && this.peek().v === '&' ? this.next() : null;
                if (!this.peek() || this.peek().t !== 'id') {
                    // `function` with no name yet (the user types it).
                    reset();
                    continue;
                }
                const fname = this.next();
                const params = this.peek() && this.peek().v === '(' ? this.params() : [];
                const returnType = this.returnType();
                const range = this.bodyRange();
                const method = {
                    name: fname ? fname.v : '',
                    params,
                    returnType,
                    modifiers,
                    visibility: visibilityOf(modifiers),
                    static: modifiers.includes('static'),
                    byRef: byRef !== null,
                    attrs: memberAttrs,
                    doc: docBefore(this.text, memberStart),
                    offset: fname ? fname.start : tok.start,
                    start: memberStart,
                    ...range,
                    end: range.bodyEnd >= 0 ? range.bodyEnd : (fname ? fname.end : tok.end),
                };
                cls.methods.push(method);
                if (method.name.toLowerCase() === '__construct') {
                    for (const p of params.filter((q) => q.promoted)) {
                        cls.properties.push({
                            name: p.name,
                            type: p.type,
                            modifiers: p.modifiers,
                            visibility: visibilityOf(p.modifiers),
                            static: false,
                            attrs: p.attrs,
                            doc: docBefore(this.text, p.offset),
                            default: p.default,
                            promoted: true,
                            offset: p.offset,
                        });
                    }
                }
                // Skip the body (or the `;` of an abstract method).
                while (this.k < this.tokens.length && this.peek().v !== '{' && this.peek().v !== ';' && this.peek().v !== '}') {
                    this.next();
                }
                if (this.peek() && this.peek().v === '{') {
                    this.skipGroup();
                } else if (this.peek() && this.peek().v === ';') {
                    this.next();
                }
                reset();
                continue;
            }
            if (tok.t === 'id' && /^use$/i.test(tok.v)) {
                // Traits: use A, B; or use A, B { ... }
                this.next();
                while (this.k < this.tokens.length && !/^[;{}]$/.test(this.peek().v)) {
                    const t = this.next();
                    if (t.t === 'id') {
                        cls.traits.push(t.v);
                    }
                }
                if (this.peek() && this.peek().v === '{') {
                    this.skipGroup();
                } else if (this.peek() && this.peek().v === ';') {
                    this.next();
                }
                reset();
                continue;
            }
            if (tok.t === 'id' && /^(const|case)$/i.test(tok.v)) {
                this.constants(cls, tok.v.toLowerCase(), modifiers, memberStart);
                reset();
                continue;
            }
            if (modifiers.length > 0) {
                this.property(cls, modifiers, memberAttrs, memberStart);
                reset();
                continue;
            }
            this.next();
            reset();
        }
    }

    // Reads `const [type] A = 1, B = 2;` or `case A = 'a';`. The current token is
    // `const` or `case`.
    constants(cls, keyword, modifiers, memberStart) {
        this.next();
        const doc = docBefore(this.text, memberStart);
        let expectName = true;
        let last = null;
        while (this.k < this.tokens.length) {
            const tok = this.peek();
            if (tok.v === ';' || tok.v === '}') {
                if (tok.v === ';') {
                    this.next();
                }
                break;
            }
            if (tok.v === '(' || tok.v === '[' || tok.v === '{') {
                this.skipGroup();
                continue;
            }
            this.next();
            if (expectName && tok.t === 'id') {
                last = tok;
                continue;
            }
            if (expectName && (tok.v === '=' || keyword === 'case') && last) {
                cls.constants.push({
                    name: last.v,
                    kind: keyword,
                    visibility: visibilityOf(modifiers),
                    doc,
                    value: '',
                    offset: last.start,
                });
                expectName = false;
                last = null;
            }
            if (tok.v === ',') {
                expectName = true;
            }
        }
        if (expectName && last && keyword === 'case') {
            cls.constants.push({ name: last.v, kind: keyword, visibility: 'public', doc, value: '', offset: last.start });
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
    property(cls, modifiers, attrs, memberStart) {
        const doc = docBefore(this.text, memberStart);
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
                    visibility: visibilityOf(modifiers),
                    static: modifiers.includes('static'),
                    attrs,
                    doc,
                    offset: tok.start,
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
    return { name: '', attrs: [], modifiers: [], variadic: false, byRef: false, promoted: false, offset: -1 };
}

// Returns the `/** ... */` block just before a declaration, or ''. Only
// modifiers, attributes, and a type can be between the block and `offset`.
function docBefore(text, offset) {
    const before = text.slice(Math.max(0, offset - 4000), offset);
    const close = before.lastIndexOf('*/');
    if (close < 0) {
        return '';
    }
    const open = before.lastIndexOf('/**', close);
    if (open < 0 || before.slice(open, close).includes('*/') || /[;{}$]/.test(before.slice(close + 2).replace(/#\[[^\]]*\]/g, ''))) {
        return '';
    }
    return before.slice(open, close + 2);
}

function visibilityOf(modifiers) {
    return modifiers.find((m) => m === 'public' || m === 'protected' || m === 'private') || 'public';
}

function hasAttr(attrs, name) {
    return attrs.some((a) => a === name || a.endsWith('\\' + name));
}

// A prop of a class component: a `#[Prop]` (or `#[Path]`) property that is public, not
// static, not promoted, and not `#[State]`.
function isProp(p) {
    return (hasAttr(p.attrs, 'Prop') || hasAttr(p.attrs, 'Path')) && !p.promoted && p.modifiers.includes('public') && !p.modifiers.includes('static') && !hasAttr(p.attrs, 'State');
}

function isHtmlType(type) {
    return /^\\?(Mamu\\)?Html$/.test(type.replace(/^\?/, '').replace(/^null\||\|null$/i, ''));
}

// Turns the parsed functions and classes into components.
function components(parsed) {
    const list = [];
    for (const fn of parsed.functions) {
        // A function without `#[Component]` is a plain PHP function.
        if (!hasAttr(fn.attrs, 'Component')) {
            continue;
        }
        const comp = {
            kind: 'function',
            name: fn.name,
            fqn: fn.fqn,
            namespace: fn.namespace,
            page: false,
            props: [],
            slots: [],
            children: false,
            rest: false,
            offset: fn.offset,
            signature: `#[Component]\nfunction ${fn.name}(${fn.params.map(paramText).join(', ')})`,
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
        if (cls.kind !== 'class' || !(hasAttr(cls.attrs, 'Component') || hasAttr(cls.attrs, 'Page'))) {
            continue;
        }
        const comp = {
            kind: 'class',
            name: cls.name,
            fqn: cls.fqn,
            namespace: cls.namespace,
            page: hasAttr(cls.attrs, 'Page') || hasAttr(cls.attrs, 'Route'),
            props: [],
            slots: [],
            children: false,
            rest: false,
            offset: cls.offset,
            signature: `#[Component]\nfinal class ${cls.name}`,
        };
        const lines = [];
        for (const p of cls.properties) {
            if (!isProp(p)) {
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
            lines.push(`    #[Prop] public ${p.type ? p.type + ' ' : ''}$${p.name}${p.default !== undefined ? ' = ' + p.default : ''};`);
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

// The components of the runtime: they have no source file.
const BUILTIN_COMPONENTS = [
    {
        kind: 'class',
        name: 'Root',
        fqn: 'Mamu\\Root',
        namespace: 'Mamu',
        page: false,
        props: [{ name: 'tag', type: 'string', default: "'div'", required: false }],
        slots: [],
        children: true,
        rest: true,
        offset: -1,
        signature: "final class Root\n{\n    public string $tag = 'div';\n    public ?Html $children = null;\n}",
        doc: 'The root element of the page, in the document: it keeps the state of the page and gets its events. The other attributes go to the element.',
        file: null,
    },
];

function parse(text) {
    const parsed = new Parser(text).parse();
    parsed.components = components(parsed);
    return parsed;
}

module.exports = { parse, tokenize, docBefore, hasAttr, isProp, paramText, shortName, namespaceOf, joinName, BUILTIN_COMPONENTS };
