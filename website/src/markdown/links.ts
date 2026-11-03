import { posix } from 'node:path';
import { defineHastPlugin } from 'satteri';

// A link to another doc (`testing.md#setup`) goes to its page on the site. A link to another
// file of the repository (`../recipe`) goes to GitHub. The Markdown files keep their relative
// links, so they also work on GitHub.
export function links(repository: string) {
    const rewrite = (href: string): string => {
        if (/^([a-z][a-z0-9+.-]*:|#|\/)/i.test(href)) {
            return href;
        }

        const [path, hash = ''] = href.split('#');
        const fragment = hash ? `#${hash}` : '';
        const doc = /^([\w-]+)\.md$/.exec(path);

        if (doc) {
            return `/${doc[1]}/${fragment}`;
        }

        return `${repository}/blob/main/${posix.normalize(posix.join('docs', path))}${fragment}`;
    };

    return defineHastPlugin({
        name: 'phem-links',
        element: {
            filter: ['a'],
            visit(node, ctx) {
                const href = node.properties?.href;

                if (typeof href === 'string' && rewrite(href) !== href) {
                    ctx.setProperty(node, 'href', rewrite(href));
                }
            },
        },
    });
}
