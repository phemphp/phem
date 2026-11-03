import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRoute } from 'astro';
import { docs } from '../docs';

// The raw Markdown of each doc, the same bytes as the file in docs/, for LLMs.
export async function getStaticPaths() {
    return (await docs()).map((doc) => ({ params: { slug: doc.slug }, props: { file: resolve(doc.entry.filePath!) } }));
}

export const GET: APIRoute = ({ props }) =>
    new Response(readFileSync(props.file), { headers: { 'Content-Type': 'text/markdown; charset=utf-8' } });
