'use strict';

// PHP types: native types, docblock types, and name resolution.
//
// A resolved type is a plain object (or null when the type is not known):
//   { fqn }            an object of a class
//   { item }           a list (array, iterable) of `item` values
//   { fqn, item }      an iterable class, such as Collection<int, Foo>
//   { keys }           an array shape: [{ name, offset, type }]

const { importsOf } = require('./imports');

const BUILTIN = new Set([
    'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'mixed', 'void', 'null',
    'false', 'true', 'callable', 'iterable', 'object', 'never', 'resource', 'scalar', 'numeric',
    'list', 'non-empty-list', 'non-empty-array', 'array-key', 'class-string', 'non-empty-string',
    'positive-int', 'negative-int', 'non-negative-int', 'literal-string', 'callable-string',
]);
const ITERABLE = /^(array|list|non-empty-list|non-empty-array|iterable)$/i;

// Splits `a|b<c|d>|e` at the top level.
function splitTop(type, sep) {
    const parts = [];
    let depth = 0;
    let start = 0;
    for (let i = 0; i < type.length; i++) {
        const c = type[i];
        if (c === '<' || c === '(' || c === '{') {
            depth++;
        } else if (c === '>' || c === ')' || c === '}') {
            depth--;
        } else if (depth === 0 && sep.includes(c)) {
            parts.push(type.slice(start, i).trim());
            start = i + 1;
        }
    }
    parts.push(type.slice(start).trim());
    return parts.filter(Boolean);
}

// Returns the base name and the generic arguments of `Foo<A, B>`.
function generic(part) {
    const lt = part.indexOf('<');
    if (lt < 0 || !part.endsWith('>')) {
        return { base: part, args: [] };
    }
    return { base: part.slice(0, lt), args: splitTop(part.slice(lt + 1, -1), ',') };
}

// The name to resolve for `name`: `\Foo` is a fully qualified name.
function resolveName(parsed, name) {
    if (!name) {
        return null;
    }
    if (name.startsWith('\\')) {
        return name.slice(1);
    }
    if (BUILTIN.has(name.toLowerCase())) {
        return null;
    }
    const [first, ...rest] = name.split('\\');
    const imported = importsOf(parsed, 'class').find((i) => i.alias.toLowerCase() === first.toLowerCase());
    if (imported) {
        return [imported.fqn, ...rest].join('\\');
    }
    return parsed.namespace ? parsed.namespace + '\\' + name : name;
}

// Turns a type string into a resolved type. `ctx` is { parsed, self, static, parent }:
// the file of the declaration, the class for `self`, the class for `static` and
// `$this` (the called class; `self` when not set), and the parent class.
function toType(type, ctx) {
    if (!type) {
        return null;
    }
    // A conditional type: ($a is Foo ? A : B). Use the last branch.
    const cond = /^\(\s*\$\w+\s+is\s+[\s\S]*\)$/.exec(type.trim());
    if (cond) {
        const inner = type.trim().slice(1, -1);
        const colon = splitTop(inner, ':');
        return colon.length > 1 ? toType(colon[colon.length - 1], ctx) : null;
    }
    const parts = splitTop(type.replace(/^\?/, ''), '|&').map((p) => p.replace(/^\(|\)$/g, '').replace(/^\?/, ''));
    for (const part of parts) {
        if (/^(null|false|true|void|never|mixed)$/i.test(part)) {
            continue;
        }
        if (part.endsWith('[]')) {
            return { item: toType(part.slice(0, -2), ctx) };
        }
        const { base, args } = generic(part);
        if (ITERABLE.test(base)) {
            if (args.length) {
                return { item: toType(args[args.length - 1], ctx) };
            }
            continue;
        }
        if (/^self$/i.test(base)) {
            return ctx.self ? { fqn: ctx.self } : null;
        }
        if (/^(static|\$this)$/i.test(base)) {
            return ctx.static || ctx.self ? { fqn: ctx.static || ctx.self } : null;
        }
        if (/^parent$/i.test(base)) {
            return ctx.parent ? { fqn: ctx.parent } : null;
        }
        if (base.startsWith('array{') || BUILTIN.has(base.toLowerCase()) || !/^\\?[A-Za-z_]/.test(base)) {
            continue;
        }
        const fqn = resolveName(ctx.parsed, base);
        if (!fqn) {
            continue;
        }
        const result = { fqn };
        if (args.length) {
            result.item = toType(args[args.length - 1], ctx);
        }
        return result;
    }
    return null;
}

// Reads one type at the start of `s`: `array<int, Foo>`, `Foo|null`, `array{a: int}`.
function readType(s) {
    s = s.trimStart().replace(/\s*([|&])\s*/g, '$1');
    let depth = 0;
    let i = 0;
    for (; i < s.length; i++) {
        const c = s[i];
        if (c === '<' || c === '(' || c === '{') {
            depth++;
        } else if (c === '>' || c === ')' || c === '}') {
            depth--;
        } else if (depth === 0 && /\s/.test(c)) {
            break;
        }
    }
    return s.slice(0, i).replace(/\s+/g, ' ');
}

// Reads the tags of a docblock:
//   { summary, var, return, params: {name: type}, vars: {name: type},
//     properties: [{ name, type, offset }], methods: [{ name, type, static, params, offset }] }
// The offsets are relative to the start of the docblock.
function docTags(doc) {
    const tags = { summary: '', var: '', return: '', params: {}, vars: {}, properties: [], methods: [] };
    if (!doc) {
        return tags;
    }
    const summary = [];
    const re = /^[ \t]*(?:\/\*\*|\*(?!\/))?[ \t]?(.*?)[ \t]*(?:\*\/)?$/gm;
    let m;
    let inTags = false;
    while ((m = re.exec(doc))) {
        if (m[0] === '' && re.lastIndex === m.index) {
            re.lastIndex++;
        }
        const line = m[1];
        const lineOffset = m.index + m[0].indexOf(line);
        const tag = /^@([\w-]+)\s*(.*)$/.exec(line);
        if (!tag) {
            if (!inTags && line && line !== '/**' && line !== '*/') {
                summary.push(line);
            }
            continue;
        }
        inTags = true;
        const name = tag[1].replace(/^(phpstan|psalm)-/, '');
        const rest = tag[2];
        const type = readType(rest);
        if (name === 'var') {
            const v = /\$(\w+)/.exec(rest);
            if (v) {
                tags.vars[v[1]] = type.startsWith('$') ? '' : type;
            }
            if (!type.startsWith('$')) {
                tags.var = type;
            }
        } else if (name === 'return') {
            tags.return = type;
        } else if (name === 'param') {
            const v = /\$(\w+)/.exec(rest);
            if (v && !type.startsWith('$')) {
                tags.params[v[1]] = type;
            }
        } else if (/^property(-read|-write)?$/.test(name)) {
            const v = /\$(\w+)/.exec(rest);
            if (v) {
                tags.properties.push({ name: v[1], type: type.startsWith('$') ? '' : type, offset: lineOffset + line.indexOf('$' + v[1]) });
            }
        } else if (name === 'method') {
            const mm = /^(static\s+)?(?:(\S+)\s+)?(\w+)\s*\(([^)]*)\)/.exec(rest);
            if (mm) {
                tags.methods.push({ name: mm[3], type: mm[2] || '', static: !!mm[1], params: mm[4].trim(), offset: lineOffset + line.indexOf(mm[3] + '(') });
            }
        }
    }
    tags.summary = summary.join(' ').trim();
    return tags;
}

// The type of an iterable value, for `foreach` and `{% for %}`.
function itemOf(type) {
    return type && type.item ? type.item : null;
}

module.exports = { toType, resolveName, docTags, readType, splitTop, itemOf, BUILTIN };
