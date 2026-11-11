import { readdirSync, readFileSync } from 'node:fs';
import { extname, resolve } from 'node:path';
import type { APIRoute } from 'astro';

// The images of docs/images/, at the path that the raw Markdown of a doc (/<slug>.md) links to.
// The HTML pages use the optimized copies that Astro makes in /_astro/.
const dir = resolve('../docs/images');

const types: Record<string, string> = {
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.svg': 'image/svg+xml',
    '.webp': 'image/webp',
};

export function getStaticPaths() {
    return readdirSync(dir)
        .filter((file) => extname(file) in types)
        .map((file) => ({ params: { file } }));
}

export const GET: APIRoute = ({ params }) =>
    new Response(readFileSync(resolve(dir, params.file!)), { headers: { 'Content-Type': types[extname(params.file!)] } });
