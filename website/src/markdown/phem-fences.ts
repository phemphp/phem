import { defineMdastPlugin } from 'satteri';

// The docs mark the .phem code as `php`, so GitHub colors it. The site colors it with the phem
// grammar (PHP with markup and Twig). The grammar also colors plain PHP.
export const phemFences = defineMdastPlugin({
    name: 'phem-fences',
    code(node, ctx) {
        if ('php' === node.lang) {
            ctx.setProperty(node, 'lang', 'phem');
        }
    },
});
