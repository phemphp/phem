import { defineMdastPlugin } from 'satteri';

// The docs mark the .phpm code as `php`, so GitHub colors it. The site colors it with the phpm
// grammar (PHP with markup and Twig). The grammar also colors plain PHP.
export const phpmCode = defineMdastPlugin({
    name: 'phem-phpm-code',
    code(node, ctx) {
        if ('php' === node.lang) {
            ctx.setProperty(node, 'lang', 'phpm');
        }
    },
});
