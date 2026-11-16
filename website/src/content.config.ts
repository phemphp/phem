import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';

// The site reads the Markdown files of docs/ and CHANGELOG.md as they are: GitHub and LLMs read
// the same files. The id of a file is its name in lowercase, with no directory (changelog).
// The guides for the work on Phem itself (contributing, releasing) stay on GitHub only.
const docs = defineCollection({
    loader: glob({
        base: '..',
        pattern: ['docs/*.md', '!docs/contributing.md', '!docs/releasing.md', 'CHANGELOG.md'],
        generateId: ({ entry }) => entry.replace(/^.*\//, '').replace(/\.md$/, '').toLowerCase(),
    }),
});

export const collections = { docs };
