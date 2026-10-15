'use strict';

// The completion engine. It does not use the vscode API, so the tests can run
// it with plain Node. complete() returns plain items; extension.js turns them
// into vscode.CompletionItem objects.
//
// An item is:
//   { label, kind, detail, documentation, insert, snippet, range: [start, end],
//     edits: [{ offset, text }], sortText, retrigger }

const path = require('path');
const { scan } = require('./scanner');
const { parse, shortName } = require('./php');
const { importFor, resolveComponent } = require('./imports');

const EVENTS = ['onClick', 'onDoubleClick', 'onChange', 'onInput', 'onSubmit', 'onKeyDown', 'onKeyUp', 'onFocus', 'onBlur'];
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

function componentDoc(comp, root) {
    const lines = ['```php', comp.signature, '```'];
    if (comp.file) {
        lines.push('', 'Defined in `' + (root ? path.relative(root, comp.file) : comp.file) + '`');
    }
    if (comp.slots.length) {
        lines.push('', 'Slots: ' + comp.slots.map((s) => '`' + s + '`').join(', '));
    }
    return lines.join('\n');
}

class Completer {
    constructor({ text, offset, file, project }) {
        this.text = text;
        this.offset = offset;
        this.file = file;
        this.project = project;
        this.parsed = parse(text);
        this.root = project ? project.root : null;
    }

    components() {
        return this.project ? this.project.components(this.file, this.parsed) : this.parsed.components.map((c) => ({ ...c, file: this.file }));
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

    run() {
        const { context } = scan(this.text.slice(0, this.offset));
        if (!context) {
            return [];
        }
        switch (context.kind) {
            case 'tagName':
                return context.partial.includes(':') ? this.slots(context) : this.tags(context);
            case 'closeTag':
                return this.closeTag(context);
            case 'attr':
                return this.attrs(context);
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

    // Props of a component, or rhp attributes of an HTML element.
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
                add(event, `${event}={{ \${1} }}`, 'rephp event: the handler runs on the server', '1' + event);
            }
            add('className', 'className="${1}"', 'Alias for class', '0className');
            if (tag === 'label') {
                add('htmlFor', 'htmlFor="${1}"', 'Alias for for', '0htmlFor');
            }
            add('key', 'key={{ ${1} }}', 'The key of the item in a list', '2key');
        }
        return items;
    }

    php() {
        const before = this.text.slice(0, this.offset);
        // The text of the current statement, up to the cursor.
        const open = before.lastIndexOf('<?php');
        const stmt = before.slice(Math.max(before.lastIndexOf(';') + 1, before.lastIndexOf('}') + 1, open < 0 ? 0 : open + 5));

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

function complete(options) {
    return new Completer(options).run();
}

module.exports = { complete };
