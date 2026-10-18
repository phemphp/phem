'use strict';

// Finds the symbol at an offset, for go to definition and hover. It does not
// use the vscode API.
//
// symbolAt() returns null, or:
//   { range: [start, end], location: { file, offset, line, character } | null,
//     hover: markdown | null }

const path = require('path');
const { scan } = require('./scanner');
const { parse, paramText, BUILTIN_COMPONENTS } = require('./php');
const { resolveComponent } = require('./imports');
const { scopeAt, codeOf, chainStart } = require('./scope');
const { twigEnv } = require('./twig');
const { resolveName, docTags } = require('./types');
const html = require('./data/html');
const twig = require('./data/twig');

const EVENT_DOC = 'Mamu event: the handler runs on the server. It receives a `Mamu\\Event`.';

function code(lang, text) {
    return '```' + lang + '\n' + text + '\n```';
}

// Line and character (UTF-16 units, as VS Code counts) of an offset.
function position(text, offset) {
    const before = text.slice(0, offset);
    const line = before.split('\n').length - 1;
    return { line, character: offset - (before.lastIndexOf('\n') + 1) };
}

class Resolver {
    constructor({ text, offset, file, project }) {
        this.text = text;
        this.offset = offset;
        this.file = file;
        this.project = project;
        this.parsed = parse(text);
        this.source = { file, text, parsed: this.parsed };
        this.root = project ? project.root : null;
    }

    rel(file) {
        return this.root ? path.relative(this.root, file) : file;
    }

    definedIn(file) {
        return file ? '\n\nDefined in `' + this.rel(file) + '`' : '';
    }

    async location(file, offset) {
        if (!file || offset === undefined || offset < 0) {
            return null;
        }
        let text = file === this.file ? this.text : null;
        if (text === null && this.project) {
            const source = await this.project.classes.source(file, this.source);
            text = source ? source.text : null;
        }
        if (text === null) {
            return null;
        }
        return { file, offset, ...position(text, offset) };
    }

    components() {
        return this.project ? this.project.components(this.file, this.parsed) : [...this.parsed.components.map((c) => ({ ...c, file: this.file })), ...BUILTIN_COMPONENTS];
    }

    async run() {
        const t = this.text;
        // A tag or an attribute name can have `-` (aria-label); a PHP or Twig
        // name cannot (`$a->b`, `i-1`).
        const extend = (re) => {
            let i = this.offset;
            while (i < t.length && re.test(t[i])) {
                i++;
            }
            return i;
        };
        let start = this.offset;
        while (start > 0 && /[\w$\\-]/.test(t[start - 1])) {
            start--;
        }
        let end = extend(/[\w-]/);
        if (start === end) {
            return null;
        }
        let { context } = scan(t.slice(0, end));
        if (context && (context.kind === 'php' || context.kind === 'twig')) {
            end = extend(/\w/);
            context = scan(t.slice(0, end)).context;
        }
        if (!context) {
            return null;
        }
        switch (context.kind) {
            case 'tagName':
                return this.tag(context.partial, context.start, end);
            case 'closeTag':
                return context.partial === null ? null : this.tag(context.partial, context.start, end);
            case 'attr':
                return this.attr(context, end);
            case 'twig':
                return this.twig(context, end);
            case 'php':
                return context.string || context.comment ? null : this.php(end);
            default:
                return null;
        }
    }

    // <Name, <Name:slot, </Name, <div
    async tag(name, start, end) {
        if (/^[a-z]/.test(name) && !name.includes(':')) {
            const el = html.ELEMENTS[name];
            return el ? { range: [start, end], location: null, hover: code('html', `<${name}>`) + '\n\n' + el[0] } : null;
        }
        const colon = name.indexOf(':');
        const compName = colon < 0 ? name : name.slice(0, colon);
        const comp = resolveComponent(this.parsed, this.components(), compName);
        if (!comp) {
            return null;
        }
        if (colon >= 0 && this.offset > start + colon) {
            const slot = name.slice(colon + 1);
            const decl = await this.propDecl(comp, slot);
            return {
                range: [start + colon + 1, end],
                location: decl ? await this.location(comp.file, decl.offset) : null,
                hover: code('php', `?Html $${slot}`) + `\n\nSlot of \`${comp.fqn}\`` + this.definedIn(comp.file),
            };
        }
        return {
            range: [start, start + compName.length],
            location: await this.location(comp.file, comp.offset),
            hover: this.componentHover(comp),
        };
    }

    componentHover(comp) {
        const lines = [code('php', comp.signature)];
        if (comp.doc) {
            lines.push('', comp.doc);
        }
        if (comp.page) {
            const routes = this.project ? this.project.routes(this.file, this.parsed).filter((r) => r.fqn === comp.fqn) : [];
            lines.push('', 'Page' + (routes.length ? ': ' + routes.map((r) => '`' + r.path + '` (`' + r.name + '`)').join(', ') : ''));
        }
        if (comp.slots.length) {
            lines.push('', 'Slots: ' + comp.slots.map((s) => '`' + s + '`').join(', '));
        }
        return lines.join('\n') + this.definedIn(comp.file);
    }

    // The parameter or the property of a prop (or a slot) of a component.
    async propDecl(comp, name) {
        if (!comp.file) {
            return null;
        }
        const source = comp.file === this.file ? this.source : this.project && (await this.project.classes.source(comp.file, this.source));
        if (!source) {
            return null;
        }
        if (comp.kind === 'class') {
            const cls = source.parsed.classes.find((c) => c.fqn === comp.fqn);
            return cls ? cls.properties.find((p) => p.name === name) || null : null;
        }
        const fn = source.parsed.functions.find((f) => f.fqn === comp.fqn);
        return fn ? fn.params.find((p) => p.name === name) || null : null;
    }

    // An attribute name: a prop of a component, or an HTML attribute.
    async attr(ctx, end) {
        const name = ctx.partial;
        const range = [end - name.length, end];
        if (/^\\?[A-Z]/.test(ctx.tag) && !ctx.tag.includes(':')) {
            const comp = resolveComponent(this.parsed, this.components(), ctx.tag);
            const prop = comp && comp.props.find((p) => p.name === name);
            if (!prop) {
                return null;
            }
            const decl = await this.propDecl(comp, name);
            const text = `${prop.type ? prop.type + ' ' : ''}$${prop.name}${prop.default !== undefined ? ' = ' + prop.default : ''}`;
            return {
                range,
                location: decl ? await this.location(comp.file, decl.offset) : null,
                hover: code('php', text) + `\n\nProp of \`${comp.fqn}\`${prop.required ? ' (required)' : ''}` + this.definedIn(comp.file),
            };
        }
        if (/^on[A-Z]/.test(name)) {
            return { range, location: null, hover: code('html', name) + '\n\n' + EVENT_DOC };
        }
        const doc = html.GLOBAL_ATTRIBUTES[name] || (name === 'className' ? 'Alias for class' : name === 'htmlFor' ? 'Alias for for' : null)
            || (html.attributesFor(ctx.tag).includes(name) ? `<${ctx.tag}> attribute` : null);
        return doc ? { range, location: null, hover: code('html', name) + '\n\n' + doc } : null;
    }

    async twig(ctx, end) {
        const t = this.text;
        if (ctx.string) {
            // path('route_name')
            const before = t.slice(ctx.start, ctx.quote);
            if (!this.project || !/\b(path|url)\s*\(\s*$/.test(before)) {
                return null;
            }
            const close = t.indexOf(t[ctx.quote], ctx.quote + 1);
            const name = t.slice(ctx.quote + 1, close < 0 ? end : close);
            const route = this.project.routes(this.file, this.parsed).find((r) => r.name === name);
            if (!route) {
                return null;
            }
            return {
                range: [ctx.quote + 1, ctx.quote + 1 + name.length],
                location: await this.location(route.file, route.offset),
                hover: code('php', `#[Page('${route.path}')]`) + `\n\nThe route of \`${route.fqn}\`` + this.definedIn(route.file),
            };
        }
        const expr = t.slice(ctx.start, end);
        const word = /[A-Za-z_]\w*$/.exec(expr);
        if (!word) {
            return null;
        }
        const range = [end - word[0].length, end];
        const head = expr.slice(0, expr.length - word[0].length);
        if (ctx.open === '{%' && /^[-~]?\s*$/.test(head)) {
            const tag = twig.TAGS[word[0]];
            // Each end tag is `end` and the name of the tag that it closes.
            const opener = !tag && /^end\w/.test(word[0]) ? word[0].slice(3) : null;
            if (!tag && !opener) {
                return null;
            }
            return { range, location: null, hover: code('twig', `{% ${word[0]} %}`) + '\n\n' + (tag ? tag[1] : `The end of the {% ${opener} %} block`) };
        }
        if (/\|\s*$/.test(head)) {
            const f = twig.FILTERS[word[0]];
            return f ? { range, location: null, hover: code('twig', '|' + f[0]) + '\n\n' + f[1] } : null;
        }
        if (/(?:^|[^\w.])is\s+(?:not\s+)?$/.test(head)) {
            const test = twig.TESTS[word[0]];
            return test ? { range, location: null, hover: code('twig', 'is ' + word[0]) + '\n\n' + test[1] } : null;
        }

        const env = await twigEnv(this.source, ctx.literal, ctx.start - 2, this.project);
        const chain = /([A-Za-z_][\w.]*(?:\[[^\]]*\])*(?:\s*\|\s*\w+)*)\s*\.\s*$/.exec(head);
        if (chain && !/\.\.\s*$/.test(head)) {
            const type = await env.typeOf(chain[1]);
            const attr = await env.attribute(type, word[0]);
            if (!attr) {
                return null;
            }
            if (attr.kind === 'key') {
                return {
                    range,
                    location: attr.key.offset >= 0 ? await this.location(this.file, attr.key.offset) : null,
                    hover: code('twig', word[0]) + '\n\n' + (attr.key.doc || 'Array key'),
                };
            }
            return this.memberResult(attr.member, range);
        }
        if (/^\s*\(/.test(t.slice(end)) && twig.FUNCTIONS[word[0]] && !(await env.variable(word[0]))) {
            const f = twig.FUNCTIONS[word[0]];
            return { range, location: null, hover: code('twig', f[0]) + '\n\n' + f[1] };
        }
        const v = await env.variable(word[0]);
        if (!v) {
            const f = twig.FUNCTIONS[word[0]];
            return f ? { range, location: null, hover: code('twig', f[0]) + '\n\n' + f[1] } : null;
        }
        if (v.member) {
            return this.memberResult(v.member, range);
        }
        const kind = v.kind === 'twig' ? v.detail : v.kind === 'param' ? 'Parameter' : v.kind === 'local' ? 'Local variable' : v.kind;
        return {
            range,
            location: await this.location(v.file || this.file, v.offset),
            hover: code('php', `${v.typeText ? v.typeText + ' ' : ''}$${v.name}`) + '\n\n' + kind,
        };
    }

    async memberResult(m, range) {
        return {
            range,
            location: await this.location(m.file, m.offset),
            hover: code('php', m.signature.trim()) + (m.doc ? '\n\n' + m.doc : '') + `\n\n\`${m.owner}\`` + this.definedIn(m.file),
        };
    }

    async classResult(fqn, range) {
        const found = this.project ? await this.project.classes.find('class', fqn, this.source) : null;
        if (!found) {
            return null;
        }
        const cls = found.decl;
        let sig = `${cls.kind} ${cls.fqn}`;
        if (cls.extends.length) {
            sig += ' extends ' + cls.extends.join(', ');
        }
        if (cls.implements.length) {
            sig += ' implements ' + cls.implements.join(', ');
        }
        const summary = docTags(cls.doc).summary;
        return {
            range,
            location: await this.location(found.source.file, cls.offset),
            hover: code('php', sig) + (summary ? '\n\n' + summary : '') + this.definedIn(found.source.file),
        };
    }

    async functionResult(found, range) {
        const fn = found.decl;
        const ret = docTags(fn.doc).return || fn.returnType;
        const summary = docTags(fn.doc).summary;
        return {
            range,
            location: await this.location(found.source.file, fn.offset),
            hover: code('php', `function ${fn.fqn}(${fn.params.map(paramText).join(', ')})${ret ? ': ' + ret : ''}`) + (summary ? '\n\n' + summary : '') + this.definedIn(found.source.file),
        };
    }

    async php(end) {
        const t = this.text;
        const masked = codeOf(this.source);
        let start = end;
        while (start > 0 && /[\w\\]/.test(masked[start - 1])) {
            start--;
        }
        const isVar = masked[start - 1] === '$';
        const name = t.slice(start, end);
        if (!name) {
            return null;
        }
        const range = [isVar ? start - 1 : start, end];
        const scope = await scopeAt(this.source, start, this.project);
        const before = masked.slice(0, isVar ? start - 1 : start).trimEnd();
        const after = masked.slice(end).trimStart();
        const call = after.startsWith('(');

        // $a->b, $a?->b, A::b, A::$b
        const op = /(->|\?->|::)$/.exec(before);
        if (op) {
            const opStart = before.length - op[1].length;
            const exprStart = chainStart(masked, opStart);
            const exprText = masked.slice(exprStart, opStart).trim();
            if (op[1] === '::' && /^class$/i.test(name)) {
                return null;
            }
            const type = /^\\?[A-Za-z_][\w\\]*$/.test(exprText) ? scope.classType(exprText) : await scope.typeOfRange(exprStart, opStart);
            if (!type || !type.fqn || !this.project) {
                return null;
            }
            const kind = isVar ? 'property' : call ? 'method' : op[1] === '::' ? 'constant' : 'property';
            const m = await this.project.classes.member(type.fqn, name, kind, this.source);
            return m ? this.memberResult(m, range) : null;
        }

        if (isVar) {
            if (name === 'this') {
                return scope.cls ? this.classResult(scope.cls.fqn, range) : null;
            }
            const v = scope.vars.get(name);
            if (!v) {
                return null;
            }
            return {
                range,
                location: await this.location(this.file, v.offset),
                hover: code('php', `${v.typeText ? v.typeText + ' ' : ''}$${v.name}`) + '\n\n' + (v.kind === 'param' ? 'Parameter' : 'Local variable'),
            };
        }

        // A name in a use statement: use Foo\Bar; use function Foo\bar;
        const stmtStart = Math.max(masked.lastIndexOf(';', start) + 1, masked.lastIndexOf('{', start) + 1, masked.lastIndexOf('}', start) + 1);
        const useStmt = /^\s*use\s+(?:(function|const)\s+)?/i.exec(masked.slice(stmtStart, start));
        const stmt = this.parsed.uses.find((u) => u.start <= start && u.end >= end);
        if (stmt && useStmt) {
            const item = stmt.items.find((i) => i.fqn.endsWith(name.replace(/^\\/, '')) || i.alias === name);
            if (!item || !this.project) {
                return null;
            }
            if (item.kind === 'function') {
                const found = await this.project.classes.find('function', item.fqn, this.source);
                return found ? this.functionResult(found, range) : null;
            }
            return this.classResult(item.fqn, range);
        }
        if (/\b(function|class|interface|trait|enum|const|namespace)$/i.test(before)) {
            return null;
        }
        if (call && !/\bnew$/i.test(before)) {
            const found = await scope.findFunction(name);
            return found ? this.functionResult(found, range) : null;
        }
        if (/^(self|static)$/i.test(name)) {
            return scope.cls ? this.classResult(scope.cls.fqn, range) : null;
        }
        if (/^parent$/i.test(name)) {
            return scope.ctx.parent ? this.classResult(scope.ctx.parent, range) : null;
        }
        const fqn = resolveName(this.parsed, name);
        return fqn ? this.classResult(fqn, range) : null;
    }
}

async function symbolAt(options) {
    return new Resolver(options).run();
}

module.exports = { symbolAt, position };
