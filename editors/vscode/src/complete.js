'use strict';

// The completion engine. It does not use the vscode API, so the tests can run
// it with plain Node. complete() returns plain items; extension.js turns them
// into vscode.CompletionItem objects.
//
// An item is:
//   { label, kind, detail, documentation, insert, snippet, range: [start, end],
//     edits: [{ offset, text }], sortText, filterText, retrigger }
//
// `trigger` is the character that started the completion, if any.

const path = require('path');
const { scan } = require('./scanner');
const { parse, shortName, paramText, BUILTIN_COMPONENTS } = require('./php');
const { importFor, resolveComponent } = require('./imports');
const { scopeAt, codeOf, chainStart } = require('./scope');
const { twigEnv } = require('./twig');
const html = require('./data/html');
const twig = require('./data/twig');

const EVENTS = ['onClick', 'onDoubleClick', 'onChange', 'onInput', 'onSubmit', 'onKeyDown', 'onKeyUp', 'onFocus', 'onBlur'];
// The Phem attributes of an HTML element: [snippet, detail, documentation].
const PHEM_ATTRIBUTES = {
    'phem:loading': ['phem:loading={{ ${1} }}', 'Phem: show while a handler runs', 'The element shows only while an event of the handler runs: `<span phem:loading={{ save }}>Saving…</span>`.'],
    'phem:progress': ['phem:progress={{ ${1} }}', 'Phem: show the upload progress of a handler', 'The element shows only while the files of an event of the handler upload. Its `--phem-progress` CSS property (and the `value` of a `<progress>`) is the progress: `<progress phem:progress={{ save }} max="100"></progress>`.'],
    'phem:debounce': ['phem:debounce={{ ${1:300} }}', 'Phem: wait before an event is sent', 'Milliseconds: the browser sends an event of the element only when no new event comes for that time. `\'blur\'`: send on blur only. `0`: no debounce (text inputs have 150 ms by default).'],
    'phem:bind': ['phem:bind={{ ${1} }}', 'Phem: bind a field to a #[State] property', 'The field shows the property, and the next event sends its new value: `<input phem:bind={{ query }} />`.'],
    'phem:live': ['phem:live', 'Phem: send each change of a bound field', 'With `phem:bind`: each change sends an event with no handler, with the debounce of the field.'],
    'phem:bind': ['phem:bind={{ ${1} }}', 'Phem: bind a field to a #[State] property', 'The field shows the property, and the next event sends its new value: `<input phem:bind={{ query }} />`.'],
    'phem:live': ['phem:live', 'Phem: send each change of a bound field', 'With `phem:bind`: each change sends an event with no handler, with the debounce of the field.'],
    'phem:throttle': ['phem:throttle={{ ${1:300} }}', 'Phem: send at most one event per interval', 'Milliseconds: the browser sends the first event at once, then at most one event each interval, and always the last one.'],
};
const DECLARING_WORDS = /\b(namespace|function|class|interface|trait|enum|const|fn|as|goto|case)\s+$/i;

// Escapes text for a snippet.
function esc(s) {
    return s.replace(/[\\$}]/g, '\\$&');
}

function wordAfter(text, offset) {
    return /^[\w]*/.exec(text.slice(offset))[0].length;
}

function propSnippet(prop, n) {
    if (/^\??string$/i.test(prop.type)) {
        return `${esc(prop.name)}="\${${n}}"`;
    }
    return `${esc(prop.name)}={{ \${${n}} }}`;
}

// Documentation for a member: its signature and its summary.
function memberDoc(m, root) {
    const lines = ['```php', m.signature.trim(), '```'];
    if (m.doc) {
        lines.push('', m.doc);
    }
    if (m.file) {
        lines.push('', 'Defined in `' + (root ? path.relative(root, m.file) : m.file) + '`');
    }
    return lines.join('\n');
}

function componentDoc(comp, root) {
    const lines = ['```php', comp.signature, '```'];
    if (comp.doc) {
        lines.push('', comp.doc);
    }
    if (comp.file) {
        lines.push('', 'Defined in `' + (root ? path.relative(root, comp.file) : comp.file) + '`');
    }
    if (comp.slots.length) {
        lines.push('', 'Slots: ' + comp.slots.map((s) => '`' + s + '`').join(', '));
    }
    return lines.join('\n');
}

class Completer {
    constructor({ text, offset, file, project, trigger }) {
        this.text = text;
        this.offset = offset;
        this.trigger = trigger;
        this.file = file;
        this.project = project;
        this.parsed = parse(text);
        this.root = project ? project.root : null;
        this.source = { file, text, parsed: this.parsed };
    }

    // The start and the end of the word at the cursor. `chars` is a regexp
    // character class of the word characters.
    word(chars = '\\w') {
        const before = new RegExp('[' + chars + ']*$').exec(this.text.slice(0, this.offset))[0];
        const after = new RegExp('^[' + chars + ']*').exec(this.text.slice(this.offset))[0];
        return [this.offset - before.length, this.offset + after.length];
    }

    components() {
        return this.project ? this.project.components(this.file, this.parsed) : [...this.parsed.components.map((c) => ({ ...c, file: this.file })), ...BUILTIN_COMPONENTS];
    }

    symbols(kind) {
        return this.project ? this.project.allSymbols(kind, this.file, this.parsed) : [];
    }

    // '0': no import needed, '1': a project symbol, '2': a vendor symbol.
    rank(needsImport, file) {
        if (!needsImport) {
            return '0';
        }
        if (file && this.root && !path.relative(this.root, file).startsWith('vendor' + path.sep)) {
            return '1';
        }
        return '2';
    }

    // A trigger character is also an operator. It opens the list only in its
    // own context: `->`, `{%`, `</`, `<Tag`, and `$` in PHP.
    triggerFits(context) {
        const before = this.text.slice(Math.max(0, this.offset - 2), this.offset);
        switch (this.trigger) {
            case '>':
                return before.endsWith('->');
            case '%':
                return before.endsWith('{%');
            case '/':
                return context.kind === 'closeTag';
            case '<':
                return context.kind === 'tagName';
            case '$':
                return context.kind === 'php';
            default:
                return true;
        }
    }

    async run() {
        const { context } = scan(this.text.slice(0, this.offset));
        if (!context || !this.triggerFits(context)) {
            return [];
        }
        switch (context.kind) {
            case 'tagName':
                return context.partial.includes(':') ? this.slots(context) : [...this.tags(context), ...this.elements(context)];
            case 'closeTag':
                return this.closeTag(context);
            case 'attr':
                return this.attrs(context);
            case 'attrValue':
                return this.attrValues(context);
            case 'twig':
                return this.twig(context);
            case 'php':
                return context.string || context.comment ? [] : this.php();
            default:
                return [];
        }
    }

    // <Name: component tags.
    tags(ctx) {
        const end = this.offset + wordAfter(this.text, this.offset);
        const rest = this.text.slice(end);
        // The tag has attributes or an end already: complete only the name.
        const nameOnly = /^[\w:\\.-]*([ \t]+[\w{]|\s*\/?>)/.test(rest);
        const components = this.components();
        const items = [];
        const seen = new Set();

        for (const comp of components) {
            if (seen.has(comp.fqn) || (comp.page && comp.file !== this.file)) {
                continue;
            }
            seen.add(comp.fqn);
            let name = comp.name;
            let edits = [];
            const resolved = resolveComponent(this.parsed, components, comp.name);
            if (!resolved || resolved.fqn !== comp.fqn) {
                const imp = importFor(this.text, this.parsed, comp.kind === 'class' ? 'class' : 'function', comp.fqn);
                name = imp.name;
                edits = imp.edit ? [imp.edit] : [];
            }

            let snippet = esc(name);
            if (!nameOnly) {
                let n = 1;
                for (const prop of comp.props.filter((p) => p.required)) {
                    snippet += ' ' + propSnippet(prop, n++);
                }
                snippet += comp.children || comp.slots.length ? `>$0</${esc(name)}>` : ' />$0';
            }
            items.push({
                label: name,
                kind: comp.kind === 'class' ? 'class' : 'function',
                detail: comp.fqn + (comp.page ? ' (page)' : ''),
                documentation: componentDoc(comp, this.root),
                snippet,
                range: [ctx.start, end],
                edits,
                sortText: this.rank(edits.length > 0, comp.file) + name,
            });
        }
        return items;
    }

    // <name: HTML elements.
    elements(ctx) {
        if (/^[A-Z\\]/.test(ctx.partial)) {
            return [];
        }
        const end = this.offset + wordAfter(this.text, this.offset);
        const rest = this.text.slice(end);
        const nameOnly = /^[\w:\\.-]*([ \t]+[\w{]|\s*\/?>)/.test(rest);
        return Object.entries(html.ELEMENTS).map(([name, [description, isVoid]]) => ({
            label: name,
            kind: 'element',
            detail: description,
            snippet: nameOnly ? name : isVoid ? `${name}$1 />$0` : `${name}$1>$0</${name}>`,
            range: [ctx.start, end],
            edits: [],
            sortText: '5' + name,
        }));
    }

    // <Name:slot named slots.
    slots(ctx) {
        const parentName = ctx.partial.slice(0, ctx.partial.indexOf(':'));
        const comp = resolveComponent(this.parsed, this.components(), parentName);
        if (!comp) {
            return [];
        }
        const end = this.offset + wordAfter(this.text, this.offset);
        const nameOnly = /^\s*\/?>/.test(this.text.slice(end));
        return comp.slots.map((slot) => {
            const tag = `${parentName}:${slot}`;
            return {
                label: tag,
                kind: 'property',
                detail: `slot of ${comp.fqn}`,
                snippet: nameOnly ? esc(tag) : `${esc(tag)}>$0</${esc(tag)}>`,
                range: [ctx.start, end],
                edits: [],
                sortText: '0' + slot,
            };
        });
    }

    // </ the name of the open element.
    closeTag(ctx) {
        if (ctx.partial === null || !ctx.expected) {
            return [];
        }
        const end = this.offset + wordAfter(this.text, this.offset);
        const close = /^\s*>/.test(this.text.slice(end)) ? '' : '>';
        return [{
            label: ctx.expected,
            kind: 'property',
            detail: 'Close the element',
            insert: ctx.expected + close,
            range: [ctx.start, end],
            edits: [],
            sortText: '0',
        }];
    }

    // Props of a component, or Phem attributes of an HTML element.
    attrs(ctx) {
        const start = this.offset - ctx.partial.length;
        const end = this.offset + wordAfter(this.text, this.offset);
        const present = new Set(ctx.attrs);
        const items = [];
        const add = (name, snippet, detail, sortText, documentation) => {
            if (!present.has(name)) {
                items.push({ label: name, kind: 'property', detail, snippet, documentation, range: [start, end], edits: [], sortText });
            }
        };

        const tag = ctx.tag;
        if (/^\\?[A-Z]/.test(tag) && !tag.includes(':')) {
            const comp = resolveComponent(this.parsed, this.components(), tag);
            if (comp) {
                for (const prop of comp.props) {
                    const type = prop.type ? prop.type + ' ' : '';
                    const detail = `${type}$${prop.name}${prop.default !== undefined ? ' = ' + prop.default : ''}${prop.required ? ' (required)' : ''}`;
                    add(prop.name, propSnippet(prop, 1), detail, (prop.required ? '0' : '1') + prop.name);
                }
            }
            add('key', 'key={{ ${1} }}', 'The key of the item in a list', '2key');
            return items;
        }
        if (/^[a-z]/.test(tag)) {
            for (const event of EVENTS) {
                add(event, `${event}={{ \${1} }}`, 'Phem event: the handler runs on the server', '1' + event);
            }
            for (const [name, [snippet, detail, documentation]] of Object.entries(PHEM_ATTRIBUTES)) {
                add(name, snippet, detail, '1' + name, documentation);
            }
            add('className', 'className="${1}"', 'Alias for class', '0className');
            if (tag === 'label') {
                add('htmlFor', 'htmlFor="${1}"', 'Alias for for', '0htmlFor');
            }
            add('key', 'key={{ ${1} }}', 'The key of the item in a list', '2key');
            const attrSnippet = (name) => (html.BOOLEAN.has(name) ? name : `${name}="\${1}"`);
            for (const name of html.attributesFor(tag)) {
                add(name, attrSnippet(name), `<${tag}> attribute`, '0' + name);
            }
            for (const [name, description] of Object.entries(html.GLOBAL_ATTRIBUTES)) {
                if (name === 'data-') {
                    add(name, 'data-${1:name}="${2}"', description, '3' + name);
                } else if (name === 'style') {
                    add(name, 'style={{ {${1}} }}', description, '2' + name);
                } else {
                    add(name, attrSnippet(name), description, '2' + name);
                }
            }
            for (const name of html.ARIA_ATTRIBUTES) {
                add(name, `${name}="\${1}"`, 'ARIA attribute', '4' + name);
            }
        }
        return items;
    }

    // attr="...: the values of an enumerated HTML attribute.
    attrValues(ctx) {
        if (!ctx.attr || !/^[a-z]/.test(ctx.tag) || /[{}]/.test(this.text.slice(ctx.quote + 1, this.offset))) {
            return [];
        }
        const [start, end] = this.word('\\w+/.-');
        return html.valuesFor(ctx.tag, ctx.attr).map((value, i) => ({
            label: value,
            kind: 'value',
            detail: `${ctx.attr} value`,
            insert: value,
            range: [Math.max(start, ctx.quote + 1), end],
            edits: [],
            sortText: String(i).padStart(3, '0'),
        }));
    }

    async php() {
        const before = this.text.slice(0, this.offset);
        // The text of the current statement, up to the cursor.
        const open = before.lastIndexOf('<?phpm');
        const stmt = before.slice(Math.max(before.lastIndexOf(';') + 1, before.lastIndexOf('}') + 1, open < 0 ? 0 : open + 6));

        // use Foo\Bar; use function Foo\{bar, baz};
        let m = /^\s*use\s+(?:(function|const)\s+)?\\?((?:\w+\\)*)(\w*)$/i.exec(stmt);
        if (m && !/^\s*use\s+(function|const)$/i.test(stmt.trimEnd())) {
            return this.namespaceItems(m[1] ? m[1].toLowerCase() : 'class', m[2], m[3], true);
        }
        m = /^\s*use\s+(?:(function|const)\s+)?\\?((?:\w+\\)+)\{(?:[^}]*,)?\s*(?:(function|const)\s+)?((?:\w+\\)*)(\w*)$/i.exec(stmt);
        if (m) {
            const kind = (m[3] || m[1] || 'class').toLowerCase();
            return this.namespaceItems(kind, m[2] + m[4], m[5], false, m[2]);
        }

        const line = before.slice(before.lastIndexOf('\n') + 1);
        // $a->b, $a?->b, Foo::b
        m = /(->|\?->|::)\s*(\$?\w*)$/.exec(line);
        if (m) {
            return this.members(m[1], this.offset - m[0].length, m[2]);
        }
        // $name: a variable.
        m = /(?:^|[^\w\\$])\$(\w*)$/.exec(line);
        if (m) {
            return this.variables(m[1]);
        }

        // \Foo\Bar: a fully qualified name.
        m = /(?:^|[^\w\\$])\\((?:\w+\\)*)(\w*)$/.exec(line);
        if (m) {
            return [
                ...this.namespaceItems('class', m[1], m[2], false),
                ...this.namespaceItems('function', m[1], m[2], false).filter((i) => i.kind !== 'module'),
            ];
        }

        // Foo: a class or a function, imported on accept.
        m = /(?:^|[^\w\\$])([A-Za-z_]\w*)$/.exec(line);
        if (!m) {
            return [];
        }
        const head = line.slice(0, line.length - m[1].length);
        if (/(->|::|\?->)\s*$/.test(head) || DECLARING_WORDS.test(head) || /^\s*use\s/i.test(stmt)) {
            return [];
        }
        return this.nameItems(m[1].length);
    }

    // $x->, Foo::: the members of the type of the expression before `op`.
    async members(op, opStart, typed) {
        if (!this.project) {
            return [];
        }
        const code = codeOf(this.source);
        const start = chainStart(code, opStart);
        const exprText = code.slice(start, opStart).trim();
        const scope = await scopeAt(this.source, this.offset, this.project);
        const type = /^\\?[A-Za-z_][\w\\]*$/.test(exprText) ? scope.classType(exprText) : await scope.typeOfRange(start, opStart);
        if (!type || !type.fqn) {
            return [];
        }
        const isStatic = op === '::';
        const isParent = /^parent$/i.test(exprText);
        const isSelf = /^(self|static|parent|\$this)$/i.test(exprText);
        const from = scope.cls ? scope.cls.fqn : null;
        const members = await this.project.classes.members(type.fqn, this.source);
        const rangeStart = this.offset - typed.length;
        const end = this.offset + wordAfter(this.text, this.offset);
        const items = [];
        for (const mem of members) {
            if (mem.visibility === 'private' && mem.owner !== from) {
                continue;
            }
            if (mem.visibility === 'protected' && !(from && (isSelf || await this.project.classes.isA(from, mem.owner, this.source)))) {
                continue;
            }
            if (mem.kind === 'method' && mem.name.startsWith('__') && !(isParent && /^__construct$/i.test(mem.name))) {
                continue;
            }
            const doc = memberDoc(mem, this.root);
            if (isStatic) {
                if (mem.kind === 'property' && mem.static) {
                    items.push({ label: '$' + mem.name, kind: 'field', detail: mem.typeText, documentation: doc, insert: '$' + mem.name, range: [rangeStart, end], edits: [], sortText: '1' + mem.name });
                } else if (mem.kind === 'constant') {
                    items.push({ label: mem.name, kind: mem.enumCase ? 'enumMember' : 'constant', detail: mem.signature, documentation: doc, insert: mem.name, range: [rangeStart, end], edits: [], sortText: '0' + mem.name });
                } else if (mem.kind === 'method' && (mem.static || isParent || isSelf)) {
                    items.push(this.methodItem(mem, doc, rangeStart, end));
                }
            } else if (mem.kind === 'property' && !mem.static) {
                items.push({ label: mem.name, kind: 'field', detail: mem.typeText, documentation: doc, insert: mem.name, range: [rangeStart, end], edits: [], sortText: '0' + mem.name });
            } else if (mem.kind === 'method' && !mem.static) {
                items.push(this.methodItem(mem, doc, rangeStart, end));
            }
        }
        if (isStatic && !typed.startsWith('$')) {
            items.push({ label: 'class', kind: 'keyword', detail: 'The fully qualified class name', insert: 'class', range: [rangeStart, end], edits: [], sortText: '2class' });
        }
        return items;
    }

    methodItem(mem, doc, start, end) {
        const hasParams = (mem.params && mem.params.length > 0) || !!mem.paramsText;
        const call = this.text.slice(end, end + 1) === '(';
        return {
            label: mem.name,
            kind: 'method',
            detail: (mem.params ? `(${mem.params.map(paramText).join(', ')})` : `(${mem.paramsText || ''})`) + (mem.typeText ? ': ' + mem.typeText : ''),
            documentation: doc,
            snippet: call ? mem.name : hasParams ? `${mem.name}($1)$0` : `${mem.name}()$0`,
            range: [start, end],
            edits: [],
            sortText: '1' + mem.name,
        };
    }

    // $name: the variables of the scope.
    async variables(typed) {
        const scope = await scopeAt(this.source, this.offset, this.project);
        const start = this.offset - typed.length - 1;
        const end = this.offset + wordAfter(this.text, this.offset);
        const items = [];
        const add = (name, detail, sortText) => items.push({ label: '$' + name, kind: 'variable', detail, insert: '$' + name, filterText: '$' + name, range: [start, end], edits: [], sortText });
        for (const v of scope.vars.values()) {
            if (v.offset !== this.offset - typed.length - 1) {
                add(v.name, v.typeText || '', '0' + v.name);
            }
        }
        if (scope.thisType) {
            add('this', scope.cls.name, '1this');
        }
        return items;
    }

    // Twig code: tags, filters, tests, attributes, variables, and route names.
    async twig(ctx) {
        if (ctx.attr === 'phem:debounce') {
            const blur = this.debounceBlur(ctx);
            if (ctx.string) {
                return blur;
            }
            if (blur.length) {
                return [...blur, ...(await this.twigItems(ctx))];
            }
        }
        if (ctx.string) {
            return this.routeItems(ctx);
        }
        return this.twigItems(ctx);
    }

    // phem:debounce={{ 'blur' }}: send the event on blur only.
    debounceBlur(ctx) {
        const expr = this.text.slice(ctx.start, this.offset);
        const m = /^\s*(['"]?)(\w*)$/.exec(expr);
        if (!m) {
            return [];
        }
        const start = this.offset - m[1].length - m[2].length;
        const end = this.offset + wordAfter(this.text, this.offset);
        const close = m[1] && this.text[end] === m[1] ? 1 : 0;
        return [{
            label: "'blur'",
            kind: 'value',
            detail: 'phem:debounce value',
            documentation: 'Send the event when the element loses the focus.',
            insert: "'blur'",
            range: [start, end + close],
            edits: [],
            sortText: '0blur',
            filterText: m[1] + 'blur',
        }];
    }

    async twigItems(ctx) {
        const expr = this.text.slice(ctx.start, this.offset);
        const tagStart = ctx.start - 2;
        let m;
        if (ctx.open === '{%' && (m = /^[-~]?\s*(\w*)$/.exec(expr))) {
            return this.twigTags(ctx, m[1], await twigEnv(this.source, ctx.literal, tagStart, this.project));
        }
        const [start, end] = this.word();
        const item = (label, kind, detail, documentation, sortText, insert, snippet) => ({ label, kind, detail, documentation, insert, snippet, range: [start, end], edits: [], sortText });
        if (/\|\s*\w*$/.test(expr)) {
            return Object.entries(twig.FILTERS).map(([name, [sig, doc]]) => item(name, 'function', sig, doc, '0' + name, name));
        }
        if (/(?:^|[^\w.])is\s+(?:not\s+)?\w*$/.test(expr)) {
            return Object.entries(twig.TESTS).map(([name, [snippet, doc]]) => item(name, 'function', 'test', doc, '0' + name, undefined, snippet));
        }
        const env = await twigEnv(this.source, ctx.literal, tagStart, this.project);
        if ((m = /([A-Za-z_][\w.]*(?:\[[^\]]*\])*(?:\s*\|\s*\w+)*)\s*\.\s*\w*$/.exec(expr)) && !/\.\.\s*\w*$/.test(expr)) {
            const type = await env.typeOf(m[1]);
            return (await env.attributes(type)).map((a) => {
                const mem = a.member;
                const detail = a.kind === 'key' ? (a.key.doc || 'key') : a.getter ? `${mem.name}()${mem.typeText ? ': ' + mem.typeText : ''}` : mem.typeText;
                const documentation = mem ? memberDoc(mem, this.root) : undefined;
                if (a.kind === 'method') {
                    return item(a.name, 'method', detail, documentation, '1' + a.name, undefined, a.required > 0 ? `${a.name}($1)$0` : a.name);
                }
                return item(a.name, a.kind === 'key' ? 'field' : 'field', detail, documentation, '0' + a.name, a.name);
            });
        }
        // A new variable name: {% for x in, {% set x
        if (ctx.open === '{%' && /^[-~]?\s*(for\s+(\w+\s*,\s*)?|set\s+)\w*$/.test(expr)) {
            return [];
        }
        if (/[^\w\s(\[{,:?~+\-*\/%<>=!|]\s*\w*$/.test(expr.replace(/^\s*[-~]?/, ' '))) {
            return [];
        }
        const items = [];
        for (const v of await env.variables()) {
            const kind = v.kind === 'method' ? 'method' : v.kind === 'property' ? 'field' : 'variable';
            const detail = v.kind === 'method' ? 'handler' + (v.typeText ? ': ' + v.typeText : '') : v.detail || v.typeText || v.kind;
            items.push(item(v.name, kind, detail, v.member ? memberDoc(v.member, this.root) : undefined, (v.kind === 'twig' ? '0' : v.kind === 'method' ? '2' : '1') + v.name, v.name));
        }
        for (const [name, [sig, doc]] of Object.entries(twig.FUNCTIONS)) {
            items.push(item(name, 'function', sig, doc, '3' + name, undefined, `${name}($1)$0`));
        }
        for (const word of twig.KEYWORDS) {
            items.push(item(word, 'keyword', 'Twig', undefined, '4' + word, word));
        }
        return items;
    }

    // {% name: the tags, and the end tags of the open blocks first. An item
    // writes the full tag, with ` %}`. It replaces the ` %}` that follows on the
    // same line, or the `}` that VS Code adds when it closes the `{`.
    twigTags(ctx, typed, env) {
        const start = this.offset - typed.length;
        const wordEnd = this.offset + wordAfter(this.text, this.offset);
        const close = /^[ \t]*([-~]?)%\}|^\}/.exec(this.text.slice(wordEnd));
        const end = close ? wordEnd + close[0].length : wordEnd;
        const tail = ' ' + (close && close[1] ? close[1] : '') + '%}';
        const items = [];
        const add = (label, detail, documentation, snippet, sortText) => {
            if (!items.some((it) => it.label === label)) {
                items.push({ label, kind: 'keyword', detail, documentation, snippet, range: [start, end], edits: [], sortText, filterText: label });
            }
        };
        env.openTags().forEach((tag, i) => {
            const endTag = 'end' + tag;
            add(endTag, `Close the {% ${tag} %} block`, undefined, endTag + tail, '0' + i);
            if (tag === 'if' || tag === 'for') {
                add('else', twig.TAGS.else[1], undefined, 'else' + tail, '1else');
            }
            if (tag === 'if') {
                add('elseif', twig.TAGS.elseif[1], undefined, 'elseif ${1}' + tail, '1elseif');
            }
        });
        for (const [name, [snippet, doc, endTag]] of Object.entries(twig.TAGS)) {
            if (name !== 'else' && name !== 'elseif') {
                add(name, endTag ? `{% ${name} %} ... {% ${endTag} %}` : `{% ${name} %}`, doc, snippet + ' %}', '2' + name);
            }
        }
        return items;
    }

    // path('...') and url('...'): the route names of the pages.
    routeItems(ctx) {
        const before = this.text.slice(ctx.start, ctx.quote);
        if (!this.project || !/\b(path|url)\s*\(\s*$/.test(before)) {
            return [];
        }
        const [start, end] = this.word('\\w.\\-');
        return this.project.routes(this.file, this.parsed).map((r) => ({
            label: r.name,
            kind: 'value',
            detail: r.path,
            documentation: '`' + r.fqn + '`' + (this.root ? '\n\nDefined in `' + path.relative(this.root, r.file) + '`' : ''),
            insert: r.name,
            range: [Math.max(start, ctx.quote + 1), end],
            edits: [],
            sortText: '0' + r.name,
        }));
    }

    // Items for one level of a namespace: sub-namespaces and symbols.
    namespaceItems(kind, nsTyped, segment, inUse, groupPrefix = '') {
        const start = this.offset - segment.length;
        const end = this.offset + wordAfter(this.text, this.offset);
        const prefix = nsTyped.toLowerCase();
        const lineRest = this.text.slice(end, this.text.indexOf('\n', end) < 0 ? undefined : this.text.indexOf('\n', end));
        const semicolon = inUse && lineRest.trim() === '' ? ';' : '';
        const namespaces = new Map();
        const items = [];

        for (const sym of this.symbols(kind)) {
            if (!sym.fqn.toLowerCase().startsWith(prefix)) {
                continue;
            }
            const rest = sym.fqn.slice(nsTyped.length);
            const sep = rest.indexOf('\\');
            if (sep >= 0) {
                const ns = rest.slice(0, sep);
                if (!namespaces.has(ns)) {
                    namespaces.set(ns, sym.fqn.slice(0, nsTyped.length + sep));
                }
                continue;
            }
            if (!rest) {
                continue;
            }
            items.push({
                label: rest,
                kind,
                detail: groupPrefix ? sym.fqn.slice(groupPrefix.length) : sym.fqn,
                insert: rest + semicolon,
                range: [start, end],
                edits: [],
                sortText: '1' + rest,
            });
        }
        for (const [ns, fqn] of namespaces) {
            items.push({
                label: ns,
                kind: 'module',
                detail: fqn,
                insert: ns + '\\',
                range: [start, end],
                edits: [],
                sortText: '0' + ns,
                retrigger: true,
            });
        }
        return items;
    }

    // Classes and functions by short name, with an import.
    nameItems(typed) {
        const start = this.offset - typed;
        const end = this.offset + wordAfter(this.text, this.offset);
        const items = [];
        const add = (kind, sym) => {
            const imp = importFor(this.text, this.parsed, kind, sym.fqn);
            items.push({
                label: shortName(sym.fqn),
                kind,
                detail: sym.fqn,
                insert: imp.name,
                range: [start, end],
                edits: imp.edit ? [imp.edit] : [],
                sortText: this.rank(imp.edit !== null || imp.qualified, sym.file) + shortName(sym.fqn),
                filterText: shortName(sym.fqn),
            });
        };
        for (const sym of this.symbols('class')) {
            add('class', sym);
        }
        const components = new Set(this.components().map((c) => c.fqn));
        for (const sym of this.symbols('function')) {
            if (!components.has(sym.fqn) && sym.fqn.includes('\\')) {
                add('function', sym);
            }
        }
        return items;
    }
}

async function complete(options) {
    return new Completer(options).run();
}

module.exports = { complete };
