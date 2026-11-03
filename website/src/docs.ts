import { execFileSync } from 'node:child_process';
import { type CollectionEntry, getCollection, render } from 'astro:content';
import type { MarkdownHeading } from 'astro';
import { pages } from './navigation';
import { repository } from './site';

export interface Doc {
    slug: string;
    entry: CollectionEntry<'docs'>;
    title: string;
    headings: MarkdownHeading[];
    editUrl: string;
    updated?: string;
}

let loaded: Promise<Doc[]> | undefined;

// The docs in the order of the navigation. Each file of docs/ must be in the navigation once.
export function docs(): Promise<Doc[]> {
    loaded ??= load();

    return loaded;
}

async function load(): Promise<Doc[]> {
    const entries = await getCollection('docs');
    const slugs = new Set(entries.map((entry) => entry.id));
    const missing = [...slugs].filter((slug) => !pages.some((page) => page.slug === slug));
    const unknown = pages.filter((page) => !slugs.has(page.slug)).map((page) => page.slug);

    if (missing.length || unknown.length) {
        throw new Error(`src/navigation.ts does not match docs/: missing [${missing.join(', ')}], unknown [${unknown.join(', ')}].`);
    }

    return Promise.all(
        pages.map(async ({ slug }) => {
            const entry = entries.find((candidate) => candidate.id === slug)!;
            const headings = (await render(entry)).headings.map((heading) => ({ ...heading, text: heading.text.trim() }));
            const file = `docs/${slug}.md`;

            return {
                slug,
                entry,
                title: headings.find((heading) => 1 === heading.depth)?.text ?? slug,
                headings: headings.filter((heading) => 2 === heading.depth || 3 === heading.depth),
                editUrl: `${repository}/edit/main/${file}`,
                updated: lastCommit(file),
            };
        }),
    );
}

// The date of the last commit of a file (YYYY-MM-DD), or undefined with no git history.
function lastCommit(file: string): string | undefined {
    try {
        return execFileSync('git', ['log', '-1', '--format=%cs', '--', file], { cwd: '..', encoding: 'utf8' }).trim() || undefined;
    } catch {
        return undefined;
    }
}
