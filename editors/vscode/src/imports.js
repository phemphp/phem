'use strict';

// Name resolution and `use` statements, with the PHP rules that rhpc uses for
// a component tag: a `use function` import, then a `use` (class) import, then
// the current namespace.

const { shortName, namespaceOf } = require('./php');

function importsOf(parsed, kind) {
    const list = [];
    for (const stmt of parsed.uses) {
        for (const item of stmt.items) {
            if (item.kind === kind) {
                list.push(item);
            }
        }
    }
    return list;
}

// Returns the name to write for `fqn`, and the import to add (or null).
//   { name, edit: { offset, text } | null }
function importFor(text, parsed, kind, fqn) {
    const short = shortName(fqn);
    const ns = namespaceOf(fqn);
    const imports = importsOf(parsed, kind);

    const same = imports.find((i) => i.fqn.toLowerCase() === fqn.toLowerCase());
    if (same) {
        return { name: same.alias, edit: null };
    }
    if (ns.toLowerCase() === parsed.namespace.toLowerCase()) {
        return { name: short, edit: null };
    }
    if (kind !== 'class' && ns === '') {
        // A global function or constant needs no import.
        return { name: short, edit: null };
    }
    if (imports.some((i) => i.alias.toLowerCase() === short.toLowerCase())) {
        // The short name is taken: use the fully qualified name.
        return { name: '\\' + fqn, edit: null, qualified: true };
    }
    return { name: short, edit: useEdit(text, parsed, kind, fqn) };
}

function lineStart(text, offset) {
    return text.lastIndexOf('\n', offset - 1) + 1;
}

function lineEnd(text, offset) {
    const i = text.indexOf('\n', offset);
    return i < 0 ? text.length : i;
}

// Returns the edit that imports `fqn`: { offset, text }.
function useEdit(text, parsed, kind, fqn) {
    const ns = namespaceOf(fqn);
    const short = shortName(fqn);
    const keyword = kind === 'class' ? 'use' : 'use ' + kind;
    const statement = `${keyword} ${fqn};`;
    const sameKind = parsed.uses.filter((u) => u.kind === kind);

    // Add the name to a group on one line: use function App\Components\{Layout, Profile};
    const group = sameKind.find((u) => u.group && u.group.close > 0 && u.group.prefix.replace(/\\$/, '').toLowerCase() === ns.toLowerCase()
        && !text.slice(u.group.open, u.group.close).includes('\n'));
    if (group) {
        let i = group.group.close;
        while (i > group.group.open && /\s/.test(text[i - 1])) {
            i--;
        }
        return { offset: i, text: text[i - 1] === ',' ? ' ' + short : ', ' + short };
    }

    if (sameKind.length > 0) {
        // Keep the imports in alphabetical order.
        const key = fqn.toLowerCase();
        const after = sameKind.find((u) => (u.items[0] ? u.items[0].fqn.toLowerCase() : '') > key);
        if (after) {
            return { offset: lineStart(text, after.start), text: statement + '\n' };
        }
        const last = sameKind[sameKind.length - 1];
        return { offset: lineEnd(text, last.end), text: '\n' + statement };
    }

    if (parsed.uses.length > 0) {
        if (kind === 'class') {
            // Class imports go before the function and constant imports.
            const first = parsed.uses[0];
            return { offset: lineStart(text, first.start), text: statement + '\n\n' };
        }
        const classUses = parsed.uses.filter((u) => u.kind === 'class');
        const anchor = kind === 'const' || classUses.length === 0 ? parsed.uses[parsed.uses.length - 1] : classUses[classUses.length - 1];
        if (kind === 'function') {
            const constUse = parsed.uses.find((u) => u.kind === 'const');
            if (constUse) {
                return { offset: lineStart(text, constUse.start), text: statement + '\n\n' };
            }
        }
        return { offset: lineEnd(text, anchor.end), text: '\n\n' + statement };
    }

    if (parsed.namespaceEnd >= 0) {
        return { offset: lineEnd(text, parsed.namespaceEnd), text: '\n\n' + statement };
    }
    if (parsed.openTagEnd >= 0) {
        return { offset: lineEnd(text, parsed.openTagEnd), text: '\n\n' + statement };
    }
    return { offset: 0, text: statement + '\n\n' };
}

// Finds the component for a tag name, with the rules of rhpc.
function resolveComponent(parsed, components, tag) {
    const byFqn = new Map(components.map((c) => [c.fqn.toLowerCase(), c]));
    if (tag.startsWith('\\')) {
        return byFqn.get(tag.slice(1).toLowerCase()) || null;
    }
    const [first, ...rest] = tag.split('\\');
    const tail = rest.length ? '\\' + rest.join('\\') : '';
    for (const kind of ['function', 'class']) {
        for (const i of importsOf(parsed, kind)) {
            if (i.alias.toLowerCase() === first.toLowerCase()) {
                const found = byFqn.get((i.fqn + tail).toLowerCase());
                if (found) {
                    return found;
                }
            }
        }
    }
    const local = (parsed.namespace ? parsed.namespace + '\\' : '') + tag;
    return byFqn.get(local.toLowerCase()) || null;
}

module.exports = { importFor, useEdit, resolveComponent };
