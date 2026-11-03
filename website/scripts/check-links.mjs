// Checks the internal links of the built site (dist/): each page and each #anchor must exist.
// The docs keep relative .md links for GitHub, so a typo there shows here, not on GitHub.
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const dist = new URL('../dist/', import.meta.url).pathname;
const pages = new Map();

for (const entry of readdirSync(dist, { recursive: true })) {
    if (String(entry).endsWith('.html')) {
        const html = readFileSync(join(dist, entry), 'utf8');

        pages.set(String(entry), { html, ids: new Set([...html.matchAll(/\sid="([^"]+)"/g)].map(([, id]) => id)) });
    }
}

const errors = [];

for (const [file, { html }] of pages) {
    const page = `/${file.replace(/index\.html$/, '')}`;

    for (const [, href] of html.matchAll(/<[a-z][^>]*\shref="([^"]+)"/g)) {
        const url = new URL(href.replaceAll('&amp;', '&'), `https://site${page}`);

        if ('https://site' !== url.origin) {
            continue;
        }

        const path = url.pathname.slice(1);
        const target = path.endsWith('/') || '' === path ? `${path}index.html` : path;

        if (!existsSync(join(dist, target))) {
            errors.push(`${file}: ${href} (no page)`);
        } else if (url.hash && pages.has(target) && !pages.get(target).ids.has(decodeURIComponent(url.hash.slice(1)))) {
            errors.push(`${file}: ${href} (no anchor)`);
        }
    }
}

if (errors.length) {
    console.error(`${errors.length} broken link(s) in ${relative(process.cwd(), dist)}:\n  ${errors.join('\n  ')}`);
    process.exit(1);
}

console.log(`check-links: ${pages.size} page(s), no broken links.`);
