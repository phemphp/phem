import Slugger from 'github-slugger';
import { defineHastPlugin } from 'satteri';

// Each h2 and h3 gets a "#" link to itself (design system: Prose). Astro gives the ids after
// this plugin, so it gives them here with the same slugger, in the same order. The "#" is a raw
// node: it is not part of the text of the heading (the table of contents, the search).
export const anchors = () => {
    const slugger = new Slugger();

    return defineHastPlugin({
        name: 'phem-anchors',
        element: {
            filter: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            visit(node, ctx) {
                const id = typeof node.properties?.id === 'string' ? node.properties.id : slugger.slug(ctx.textContent(node));

                ctx.setProperty(node, 'id', id);

                if ('h2' !== node.tagName && 'h3' !== node.tagName) {
                    return;
                }

                ctx.appendChild(node, {
                    type: 'element',
                    tagName: 'a',
                    properties: { className: ['ph-anchor'], href: `#${id}`, ariaLabel: 'Link to this section' },
                    children: [{ type: 'raw', value: '#' }],
                });
            },
        },
    });
};
