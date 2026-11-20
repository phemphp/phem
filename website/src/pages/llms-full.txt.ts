import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRoute } from 'astro';
import { docs } from '../docs';
import { navigation } from '../navigation';

// The docs that a user needs, in one file for LLMs (https://llmstxt.org): the raw Markdown of
// each doc, in the order of the navigation. The changelog is not in it. Each doc starts with a
// comment that gives its URL.
const SKIPPED = ['changelog'];

export const GET: APIRoute = async ({ site }) => {
    const all = await docs();
    const parts = ['# Phem', '', '> UI server components for Symfony web apps. A .phem file is PHP with markup in it: phemc compiles it to PHP and Twig.', ''];

    for (const page of navigation.flatMap((group) => group.pages)) {
        if (SKIPPED.includes(page.slug)) {
            continue;
        }

        const doc = all.find((candidate) => candidate.slug === page.slug)!;
        const url = new URL(`${import.meta.env.BASE_URL}${page.slug}`, site);

        parts.push(`<!-- ${url} -->`, '', readFileSync(resolve(doc.entry.filePath!), 'utf8').trim(), '');
    }

    return new Response(parts.join('\n'), { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
