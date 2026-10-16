'use strict';

// The outline of a .rhp file: the namespace, the classes and their members,
// and the functions. A component, a page, a prop, a slot, and a state
// property get a detail text.
//
// A symbol is:
//   { name, detail, kind, range: [start, end], selection: [start, end], children }
// `kind` is namespace, class, interface, trait, enum, function, method,
// constructor, property, constant, or enumMember.

const { hasAttr } = require('./php');
const { routesOf } = require('./project');

function isHtmlType(type) {
    return /^\??\\?(Rephp\\)?Html(\|null)?$/i.test(type);
}

function symbol(name, detail, kind, start, end, offset, children = []) {
    const selStart = offset >= 0 ? offset : start;
    return { name, detail, kind, range: [start, Math.max(end, selStart + name.length)], selection: [selStart, selStart + name.length], children };
}

function pageDetail(routes, fqn, base) {
    const paths = routes.filter((r) => r.fqn === fqn).map((r) => r.path);
    return paths.length ? `page ${paths.join(', ')}` : base;
}

function documentSymbols(parsed, text) {
    const routes = routesOf(parsed, '');
    const components = new Map(parsed.components.map((c) => [c.fqn, c]));
    const list = [];

    for (const fn of parsed.functions) {
        const comp = components.get(fn.fqn);
        const children = comp ? fn.params.map((p) => {
            const detail = p.variadic ? 'rest props' : p.name === 'children' ? 'children' : isHtmlType(p.type) ? 'slot' : `prop${p.type ? ': ' + p.type : ''}`;
            return symbol(p.name, detail, 'property', p.offset, p.offset + p.name.length + 1, p.offset + 1);
        }) : [];
        const detail = comp ? pageDetail(routes, fn.fqn, 'component') : '';
        list.push(symbol(fn.name, detail, 'function', fn.start, fn.end, fn.offset, children));
    }

    for (const cls of parsed.classes) {
        const comp = components.get(cls.fqn);
        const children = [];
        for (const c of cls.constants) {
            children.push(symbol(c.name, '', c.kind === 'case' ? 'enumMember' : 'constant', c.offset, c.offset + c.name.length, c.offset));
        }
        for (const p of cls.properties) {
            let detail = p.type;
            if (comp && !p.promoted && p.modifiers.includes('public') && !p.static) {
                detail = hasAttr(p.attrs, 'State') ? 'state' : p.name === 'children' ? 'children' : isHtmlType(p.type) ? 'slot' : 'prop';
                detail += p.type ? ': ' + p.type : '';
            }
            children.push(symbol(p.name, detail, 'property', p.offset, p.offset + p.name.length + 1, p.offset + 1));
        }
        for (const m of cls.methods) {
            const kind = m.name.toLowerCase() === '__construct' ? 'constructor' : 'method';
            children.push(symbol(m.name, m.returnType, kind, m.start, m.end, m.offset));
        }
        const detail = comp ? pageDetail(routes, cls.fqn, 'component') : '';
        list.push(symbol(cls.name, detail, cls.kind, cls.start, cls.end, cls.offset, children));
    }

    list.sort((a, b) => a.range[0] - b.range[0]);
    if (parsed.namespace && parsed.namespaceStart >= 0) {
        return [symbol(parsed.namespace, '', 'namespace', parsed.namespaceStart, text.length, parsed.namespaceStart, list)];
    }
    return list;
}

module.exports = { documentSymbols };
