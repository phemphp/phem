import { defineHastPlugin, type HastNode } from 'satteri';

type Element = Extract<HastNode, { type: 'element' }>;

// A Markdown table becomes a table of the design system (design system: Table). The wrapper
// scrolls a wide table on a narrow screen, and the page does not scroll.
export const tables = defineHastPlugin({
    name: 'phem-tables',
    element: {
        filter: ['table'],
        visit(node) {
            return {
                type: 'element',
                tagName: 'div',
                properties: { className: ['ph-table-wrap'] },
                children: [{ ...withWords(node), properties: { ...node.properties, className: ['ph-table'] } }],
            };
        },
    },
});

// The code of a cell breaks only at its spaces and before a `->` of a chain of calls: each part
// is a span that does not wrap. Without the spans, the browser breaks after a hyphen (`)-` and
// `>click()` on two lines).
function withWords(node: Element): Element {
    if ('code' === node.tagName) {
        return {
            ...node,
            children: node.children.flatMap((child) => (child.type === 'text' ? words(child.value) : [child])),
        };
    }

    return {
        ...node,
        children: node.children.map((child) => (child.type === 'element' ? withWords(child) : child)),
    };
}

function words(text: string): Element['children'] {
    return text.split(/( +)/).filter(Boolean).flatMap((part): Element['children'] => {
        if (part.startsWith(' ')) {
            return [{ type: 'text', value: part }];
        }

        return part.split(/(?=->)/).flatMap((call, index): Element['children'] => [
            ...(index ? [{ type: 'element' as const, tagName: 'wbr', properties: {}, children: [] }] : []),
            { type: 'element', tagName: 'span', properties: {}, children: [{ type: 'text', value: call }] },
        ]);
    });
}
