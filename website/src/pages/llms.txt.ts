import type { APIRoute } from 'astro';
import { docs } from '../docs';
import { navigation } from '../navigation';

// The list of the docs for LLMs (https://llmstxt.org): one line for each doc, with a link to
// its raw Markdown.
export const GET: APIRoute = async ({ site }) => {
    const all = await docs();
    const lines = [
        '# Phem',
        '',
        '> UI server components for Symfony web apps. A .phpm file is PHP with markup in it: phemc compiles it to PHP and Twig.',
        '',
    ];

    for (const group of navigation) {
        lines.push(`## ${group.title}`, '');

        for (const page of group.pages) {
            const doc = all.find((candidate) => candidate.slug === page.slug)!;

            lines.push(`- [${doc.title}](${new URL(`${import.meta.env.BASE_URL}${page.slug}.md`, site)})`);
        }

        lines.push('');
    }

    return new Response(lines.join('\n'), { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
