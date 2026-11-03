import { defineHastPlugin } from 'satteri';

// The first paragraph after the title is the lead paragraph of the page (design system: Prose).
export const lead = defineHastPlugin({
    name: 'phem-lead',
    element: {
        filter: ['p'],
        visit(node, ctx) {
            const parent = ctx.parent(node);
            const index = ctx.indexOf(node);

            if (!parent || undefined === index) {
                return;
            }

            const before = parent.children.slice(0, index).filter((child) => child.type === 'element');

            if (1 === before.length && 'h1' === before[0].tagName) {
                ctx.setProperty(node, 'className', ['lw-lead']);
            }
        },
    },
});
