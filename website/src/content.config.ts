import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';

// The site reads the Markdown files of docs/ as they are: GitHub and LLMs read the same files.
const docs = defineCollection({
    loader: glob({ base: '../docs', pattern: '*.md' }),
});

export const collections = { docs };
